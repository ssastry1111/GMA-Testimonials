<?php
if (!defined('ABSPATH')) exit;

/**
 * Photo Reminder module.
 *
 * Ported from the standalone GMA Testimonial Reminder v5.10, keeping all of
 * its behaviour: IST-hardcoded scheduling, self-healing cron, once-per-IST-day
 * guard, and per-recipient maximum enforcement across auto and manual runs.
 *
 * Existing history is preserved: the log option and the per-recipient send
 * counts keep their original option names, so nobody who already had two
 * reminders starts again from zero.
 */
class GMA_T_Reminder {

    const HOOK          = 'gma_t_reminder_event';
    const LEGACY_HOOK   = 'gma_tr_event';
    const LOGS_KEY      = 'gma_tr_logs';
    const COUNTS_KEY    = 'gma_tr_send_counts';
    const LAST_RUN_KEY  = 'gma_tr_last_run';
    const NEXT_RUN_KEY  = 'gma_tr_next_run';
    const LAST_DAY_KEY  = 'gma_tr_last_sent_day';

    private $ist;

    public function __construct() {
        $this->ist = new DateTimeZone('Asia/Kolkata');

        add_action('init',        [$this, 'migrate_legacy'], 20);
        add_action('init',        [$this, 'maybe_reschedule'], 30);
        add_action(self::HOOK,    [$this, 'run']);
        add_action('admin_menu',  [$this, 'menu'], 20);
    }

    /* ── IST helpers ─────────────────────────────────────────────────────── */

    private function ist_now()      { return new DateTime('now', $this->ist); }
    private function ist_date()     { return $this->ist_now()->format('Y-m-d'); }
    private function ist_datetime() { return $this->ist_now()->format('Y-m-d H:i:s') . ' IST'; }

    /* ── Migration from the standalone plugin ────────────────────────────── */

    /**
     * One-time: pull settings across from the standalone plugin, retire its
     * cron hook, and rebuild send counts from log history if that was never
     * done. Logs and counts keep their option names, so nothing is lost.
     */
    public function migrate_legacy() {
        if (get_option('gma_t_reminder_migrated')) return;

        $legacy = get_option('gma_tr_settings', []);
        if (is_array($legacy) && !empty($legacy)) {
            $opts = get_option(GMA_T_SETTINGS, []);
            if (!is_array($opts)) $opts = [];
            foreach (['time', 'max', 'subject', 'body'] as $k) {
                if (isset($legacy[$k]) && $legacy[$k] !== '') {
                    $opts[$k] = $legacy[$k];
                }
            }
            update_option(GMA_T_SETTINGS, $opts);
        }

        // Rebuild counts from logs if the standalone migration never ran.
        if (!get_option('gma_tr_counts_migrated')) {
            $logs   = get_option(self::LOGS_KEY, []);
            $counts = [];
            if (is_array($logs)) {
                foreach ($logs as $entry) {
                    if (isset($entry['type']) && in_array($entry['type'], ['Auto', 'Manual'], true)) {
                        $email = isset($entry['email']) ? $entry['email'] : '';
                        if ($email) $counts[$email] = (isset($counts[$email]) ? $counts[$email] : 0) + 1;
                    }
                }
            }
            update_option(self::COUNTS_KEY, $counts);
            update_option('gma_tr_counts_migrated', true);
        }

        // The standalone plugin's cron hook must not fire alongside ours.
        wp_clear_scheduled_hook(self::LEGACY_HOOK);

        update_option('gma_t_reminder_migrated', true);
    }

    /* ── Scheduling ──────────────────────────────────────────────────────── */

    /** Self-healing: re-queue the event if it has vanished for any reason. */
    public function maybe_reschedule() {
        if (!gma_t_opt('reminder_enabled')) {
            wp_clear_scheduled_hook(self::HOOK);
            return;
        }
        if (!wp_next_scheduled(self::HOOK)) {
            self::schedule_next_static();
        }
    }

    public static function schedule_next_static() {
        $ist      = new DateTimeZone('Asia/Kolkata');
        $time_ist = gma_t_opt('time');
        if (!$time_ist) $time_ist = '10:00';

        $now_ist = new DateTime('now', $ist);
        $run_ist = new DateTime($time_ist, $ist);
        $run_ist->setDate($now_ist->format('Y'), $now_ist->format('m'), $now_ist->format('d'));

        // If today's slot has passed, go to tomorrow.
        if ($run_ist <= $now_ist) $run_ist->modify('+1 day');

        wp_clear_scheduled_hook(self::HOOK);
        wp_schedule_single_event($run_ist->getTimestamp(), self::HOOK);

        update_option(self::NEXT_RUN_KEY, $run_ist->format('Y-m-d H:i:s') . ' IST');
    }

    /* ── Candidates ──────────────────────────────────────────────────────── */

    /** Testimonials that have an email but no featured image. */
    public function get_candidates() {
        $posts = get_posts([
            'post_type'      => GMA_T_PT,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
        ]);

        $name_key  = gma_t_opt('meta_name');
        $email_key = gma_t_opt('meta_email');
        $results   = [];

        foreach ($posts as $p) {
            $email = get_post_meta($p->ID, $email_key, true);
            if (has_post_thumbnail($p->ID) || empty($email)) continue;

            $name = get_post_meta($p->ID, $name_key, true);
            if (!$name) $name = get_the_title($p->ID);

            $results[] = ['post' => $p, 'name' => $name, 'email' => $email];
        }
        return $results;
    }

    private function mail_headers($include_cc = true) {
        $headers = [
            'From: ' . gma_t_opt('from_name') . ' <' . gma_t_opt('from_email') . '>',
            'Reply-To: ' . gma_t_opt('from_email'),
            'Content-Type: text/html; charset=UTF-8',
        ];
        $cc = trim((string) gma_t_opt('cc_emails'));
        if ($include_cc && $cc !== '') $headers[] = 'Cc: ' . $cc;
        return $headers;
    }

    /**
     * Fill the email template for one recipient.
     *
     * {upload_url} becomes a tokenised link to that person's own testimonial,
     * so they can add a photo without logging in. Each recipient therefore
     * gets a different URL, which is why the body is rendered per send rather
     * than once per run.
     *
     * @param string $template Subject or body.
     * @param string $name     Recipient name.
     * @param string $email    Recipient email.
     * @param int    $post_id  Their testimonial, for the token.
     */
    private function personalise($template, $name, $email, $post_id = 0) {
        $parts = explode(' ', trim((string) $name));
        $first = $parts ? $parts[0] : $name;

        $upload_url = $post_id ? GMA_T_Token::upload_url($post_id) : trim((string) gma_t_opt('upload_url'));

        return str_replace(
            ['{first_name}', '{name}', '{email}', '{upload_url}'],
            [$first, $name, $email, esc_url($upload_url)],
            (string) $template
        );
    }

    /* ── Run ─────────────────────────────────────────────────────────────── */

    public function run($manual = false) {

        $today = $this->ist_date();

        // Once per IST day for automatic runs, but always leave a future event queued.
        if (!$manual && get_option(self::LAST_DAY_KEY) === $today) {
            self::schedule_next_static();
            return 0;
        }

        $max        = (int) gma_t_opt('max');
        if ($max < 1) $max = 2;
        $candidates = $this->get_candidates();
        $counts     = get_option(self::COUNTS_KEY, []);
        if (!is_array($counts)) $counts = [];

        $sent = 0;

        foreach ($candidates as $c) {
            $email = $c['email'];
            $count = isset($counts[$email]) ? (int) $counts[$email] : 0;

            if ($count >= $max) continue; // already had the maximum

            wp_mail(
                $email,
                gma_t_opt('subject'),
                $this->personalise(gma_t_opt('body'), $c['name'], $email, $c['post']->ID),
                $this->mail_headers(true)
            );

            $counts[$email] = $count + 1;

            $logs = get_option(self::LOGS_KEY, []);
            if (!is_array($logs)) $logs = [];
            $logs[] = [
                'time'  => $this->ist_datetime(),
                'name'  => $c['name'],
                'email' => $email,
                'type'  => $manual ? 'Manual' : 'Auto',
                'count' => ($count + 1) . '/' . $max,
            ];
            if (count($logs) > 500) $logs = array_slice($logs, -500);
            update_option(self::LOGS_KEY, $logs);

            $sent++;
        }

        update_option(self::COUNTS_KEY, $counts);
        update_option(self::LAST_RUN_KEY, $this->ist_datetime());
        if (!$manual) update_option(self::LAST_DAY_KEY, $today);

        self::schedule_next_static();
        return $sent;
    }

    /* ── Admin page ──────────────────────────────────────────────────────── */

    public function menu() {
        add_submenu_page(
            'edit.php?post_type=' . GMA_T_PT,
            'Photo Reminder',
            'Photo Reminder',
            'manage_options',
            'gma-t-reminder',
            [$this, 'page']
        );
    }

    public function page() {
        if (!current_user_can('manage_options')) return;

        $max     = (int) gma_t_opt('max');
        if ($max < 1) $max = 2;
        $counts  = get_option(self::COUNTS_KEY, []);
        if (!is_array($counts)) $counts = [];
        $notice  = '';

        // ── Actions ──────────────────────────────────────────────────────
        if (!empty($_POST['gma_t_reminder_action']) && check_admin_referer('gma_t_reminder')) {
            $action = sanitize_key($_POST['gma_t_reminder_action']);

            if ($action === 'run_now') {
                $sent   = $this->run(true);
                $notice = 'Manual run complete. Reminders sent: ' . (int) $sent . '.';
                $counts = get_option(self::COUNTS_KEY, []);
                if (!is_array($counts)) $counts = [];
            }

            if ($action === 'reset_counts') {
                delete_option(self::COUNTS_KEY);
                $counts = [];
                $notice = 'Send counts reset. Everyone becomes eligible again from the next run.';
            }

            if ($action === 'send_test') {
                $to         = sanitize_email(gma_t_opt('test_email'));
                $candidates = $this->get_candidates();
                $sample     = !empty($candidates) ? $candidates[0] : ['name' => 'Sample Name', 'email' => $to, 'post' => null];
                $sample_id  = !empty($sample['post']) ? (int) $sample['post']->ID : 0;

                wp_mail(
                    $to,
                    gma_t_opt('subject'),
                    $this->personalise(gma_t_opt('body'), $sample['name'], $sample['email'], $sample_id),
                    $this->mail_headers(false)
                );

                $logs = get_option(self::LOGS_KEY, []);
                if (!is_array($logs)) $logs = [];
                $logs[] = [
                    'time'  => $this->ist_datetime(),
                    'name'  => 'TEST',
                    'email' => $to,
                    'type'  => 'Test',
                    'count' => 'N/A',
                ];
                if (count($logs) > 500) $logs = array_slice($logs, -500);
                update_option(self::LOGS_KEY, $logs);

                $notice = 'Test email sent to ' . esc_html($to) . '.';
            }
        }

        // ── Cron status ──────────────────────────────────────────────────
        $ts = wp_next_scheduled(self::HOOK);
        if ($ts) {
            $dt = new DateTime('@' . $ts);
            $dt->setTimezone($this->ist);
            $cron_status = '<span style="color:#1a7f37;">' . esc_html($dt->format('Y-m-d H:i:s')) . ' IST</span>';
        } elseif (!gma_t_opt('reminder_enabled')) {
            $cron_status = '<span style="color:#996800;">Reminder module is switched off in Settings.</span>';
        } else {
            $cron_status = '<strong style="color:#d63638;">NOT SCHEDULED &mdash; will self-heal on the next page load</strong>';
        }

        $candidates = $this->get_candidates();

        echo '<div class="wrap">';
        echo '<h1>Photo Reminder <span style="font-size:0.6em;color:#666;">v' . esc_html(GMA_T_VERSION) . '</span></h1>';

        if ($notice) {
            echo '<div class="notice notice-success"><p>' . esc_html($notice) . '</p></div>';
        }

        echo '<p>Email settings for this module live on the <a href="'
           . esc_url(admin_url('edit.php?post_type=' . GMA_T_PT . '&page=gma-t-settings')) . '">Settings</a> page.</p>';

        echo '<h2>Status</h2>';
        echo '<table class="widefat striped" style="max-width:720px;"><tbody>';
        echo '<tr><td style="width:240px;"><strong>Last run</strong></td><td>' . esc_html(get_option(self::LAST_RUN_KEY) ?: 'Never') . '</td></tr>';
        echo '<tr><td><strong>Next run (IST)</strong></td><td>' . esc_html(get_option(self::NEXT_RUN_KEY) ?: 'Not recorded') . '</td></tr>';
        echo '<tr><td><strong>WP-Cron event</strong></td><td>' . $cron_status . '</td></tr>';
        echo '<tr><td><strong>Max reminders each</strong></td><td>' . (int) $max . '</td></tr>';
        echo '<tr><td><strong>Candidates right now</strong></td><td>' . count($candidates) . ' testimonial(s) with an email and no photo</td></tr>';
        echo '</tbody></table>';

        echo '<h2>Actions</h2>';
        echo '<form method="post" style="display:flex;gap:10px;flex-wrap:wrap;">';
        wp_nonce_field('gma_t_reminder');
        echo '<button class="button button-primary" name="gma_t_reminder_action" value="run_now">Run Now (Manual)</button>';
        echo '<button class="button" name="gma_t_reminder_action" value="send_test">Send Test Email</button>';
        echo '<button class="button" name="gma_t_reminder_action" value="reset_counts" '
           . 'onclick="return confirm(\'Reset all send counts? Everyone becomes eligible for reminders again.\');">Reset Send Counts</button>';
        echo '</form>';

        // ── Candidate table ──────────────────────────────────────────────
        echo '<h2>Candidates</h2>';
        if (empty($candidates)) {
            echo '<p>Every testimonial with an email address already has a photo. Nothing to do.</p>';
        } else {
            echo '<table class="widefat striped"><thead><tr>'
               . '<th>Name</th><th>Email</th><th>Sent</th><th>Status</th></tr></thead><tbody>';
            foreach ($candidates as $c) {
                $count   = isset($counts[$c['email']]) ? (int) $counts[$c['email']] : 0;
                $reached = ($count >= $max);
                echo '<tr>';
                echo '<td>' . esc_html($c['name']) . '</td>';
                echo '<td>' . esc_html($c['email']) . '</td>';
                echo '<td>' . $count . ' / ' . $max . '</td>';
                echo '<td>' . ($reached
                        ? '<span style="color:#d63638;">Max reached &mdash; will be skipped</span>'
                        : '<span style="color:#1a7f37;">Will receive a reminder</span>') . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }

        // ── Logs ─────────────────────────────────────────────────────────
        $logs = get_option(self::LOGS_KEY, []);
        if (!is_array($logs)) $logs = [];
        echo '<h2>Activity log <span style="font-weight:400;color:#666;">(most recent first, last 100 shown)</span></h2>';
        if (empty($logs)) {
            echo '<p>No reminders have been sent yet.</p>';
        } else {
            echo '<table class="widefat striped"><thead><tr>'
               . '<th>Time (IST)</th><th>Name</th><th>Email</th><th>Type</th><th>Count</th></tr></thead><tbody>';
            $recent = array_slice(array_reverse($logs), 0, 100);
            foreach ($recent as $l) {
                echo '<tr>';
                echo '<td>' . esc_html(isset($l['time']) ? $l['time'] : '') . '</td>';
                echo '<td>' . esc_html(isset($l['name']) ? $l['name'] : '') . '</td>';
                echo '<td>' . esc_html(isset($l['email']) ? $l['email'] : '') . '</td>';
                echo '<td>' . esc_html(isset($l['type']) ? $l['type'] : '') . '</td>';
                echo '<td>' . esc_html(isset($l['count']) ? $l['count'] : '-') . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }

        echo '</div>';
    }
}
