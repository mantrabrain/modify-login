<?php
/**
 * Numbers about this site, for the Dashboard and the inline cards.
 *
 * @package Authlify
 */

namespace Authlify\Admin;

use Authlify\Log\Log;

defined('ABSPATH') || exit;

/**
 * Failed logins and lockouts from the site's own activity log.
 *
 * Only ever read on Authlify's admin screens (this class is not loaded on
 * front-end requests), and cached for a few minutes so a screen view costs at
 * most three indexed queries now and then.
 *
 * @since 3.2.0
 */
final class Facts
{
    /**
     * Transient holding the counts.
     */
    const CACHE = 'authlify_nudge_facts';

    /**
     * Seconds the counts stay cached.
     */
    const TTL = 300;

    /**
     * Counts for the last 7 and 30 days, and failed logins per day.
     *
     * @return array week (failed, lockouts), month (failed, lockouts), days (Y-m-d => failed).
     */
    public static function get()
    {
        $cached = get_transient(self::CACHE);
        if (is_array($cached) && isset($cached['week'], $cached['month'], $cached['days'])) {
            return $cached;
        }

        $facts = array(
            'week' => self::pair(Log::counts(time() - 7 * DAY_IN_SECONDS)),
            'month' => self::pair(Log::counts(time() - 30 * DAY_IN_SECONDS)),
            'days' => self::query_days(),
        );
        set_transient(self::CACHE, $facts, self::TTL);

        return $facts;
    }

    /**
     * Drop the cached counts.
     */
    public static function flush()
    {
        delete_transient(self::CACHE);
    }

    /**
     * The two numbers the cards use, from a Log::counts() result.
     *
     * @param array $counts Event => count.
     * @return array failed, lockouts.
     */
    public static function pair(array $counts)
    {
        return array(
            'failed' => isset($counts['login_failed']) ? (int) $counts['login_failed'] : 0,
            'lockouts' => isset($counts['lockout']) ? (int) $counts['lockout'] : 0,
        );
    }

    /**
     * Failed logins for each of the last 7 days (today last), in the site's time zone.
     *
     * @return array Y-m-d => count.
     */
    private static function query_days()
    {
        global $wpdb;

        $zone = wp_timezone();
        $today = new \DateTimeImmutable('today', $zone);
        $days = array();
        for ($i = 6; $i >= 0; --$i) {
            $days[$today->modify('-' . $i . ' days')->format('Y-m-d')] = 0;
        }

        $since = $today->modify('-6 days')->getTimestamp();
        $offset = (int) $zone->getOffset(new \DateTimeImmutable('now', $zone));
        $table = Log::table();
        $blog = Log::scope_blog();
        $scope = $blog > 0 ? $wpdb->prepare(' AND blog_id = %d', $blog) : '';

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT DATE(DATE_ADD(created_at, INTERVAL %d SECOND)) AS d, COUNT(*) AS n FROM {$table} WHERE event = %s AND created_at >= %s{$scope} GROUP BY d",
            $offset,
            'login_failed',
            gmdate('Y-m-d H:i:s', $since)
        ));
        // phpcs:enable

        foreach ((array) $rows as $row) {
            if (isset($days[$row->d])) {
                $days[$row->d] = (int) $row->n;
            }
        }

        return $days;
    }

    /**
     * "37 failed logins and 4 lockouts", leaving out a part that is zero.
     *
     * @param array $pair failed, lockouts.
     * @return string Plain text, with numbers formatted. Empty when both are zero.
     */
    public static function phrase(array $pair)
    {
        $parts = array();
        if ($pair['failed'] > 0) {
            /* translators: %s: number of failed logins */
            $parts[] = sprintf(_n('%s failed login', '%s failed logins', $pair['failed'], 'modify-login'), number_format_i18n($pair['failed']));
        }
        if ($pair['lockouts'] > 0) {
            /* translators: %s: number of lockouts */
            $parts[] = sprintf(_n('%s lockout', '%s lockouts', $pair['lockouts'], 'modify-login'), number_format_i18n($pair['lockouts']));
        }
        if (2 === count($parts)) {
            /* translators: 1: "37 failed logins", 2: "4 lockouts" */
            return sprintf(__('%1$s and %2$s', 'modify-login'), $parts[0], $parts[1]);
        }

        return $parts ? $parts[0] : '';
    }
}
