<?php
/**
 * Ip: ranges, client() per source mode, spoofed headers, subnet, anonymize.
 *
 * @package Authlify\Tests
 */

// phpcs:ignoreFile

use Authlify\Net\Ip;

t('in_range(): IPv4 exact and CIDR', function () {
    ok(Ip::in_range('203.0.113.7', '203.0.113.7'));
    no(Ip::in_range('203.0.113.8', '203.0.113.7'));
    ok(Ip::in_range('203.0.113.200', '203.0.113.0/24'));
    no(Ip::in_range('203.0.114.1', '203.0.113.0/24'));
    ok(Ip::in_range('10.1.2.3', '10.0.0.0/8'));
    ok(Ip::in_range('172.31.255.255', '172.16.0.0/12'));
    no(Ip::in_range('172.32.0.0', '172.16.0.0/12'));
    ok(Ip::in_range('203.0.113.7', '203.0.113.7/32'));
    ok(Ip::in_range('1.2.3.4', '0.0.0.0/0'));
    ok(Ip::in_range('203.0.113.130', '203.0.113.128/25'), 'non-byte-aligned mask');
    no(Ip::in_range('203.0.113.127', '203.0.113.128/25'));
});

t('in_range(): IPv6 exact, CIDR and different notations', function () {
    ok(Ip::in_range('2001:db8::1', '2001:db8::/32'));
    ok(Ip::in_range('2001:0db8:0000::0001', '2001:db8::1'), 'same address, other notation');
    no(Ip::in_range('2001:db9::1', '2001:db8::/32'));
    ok(Ip::in_range('2001:db8:abcd:12::5', '2001:db8:abcd:12::/64'));
    no(Ip::in_range('2001:db8:abcd:13::5', '2001:db8:abcd:12::/64'));
    ok(Ip::in_range('2606:4700::1111', '2606:4700::/32'));
    ok(Ip::in_range('2001:db8::7', '2001:db8::/125'), 'non-byte-aligned IPv6 mask');
    no(Ip::in_range('2001:db8::8', '2001:db8::/125'));
});

t('in_range(): IPv4 against IPv6 ranges never matches', function () {
    no(Ip::in_range('203.0.113.7', '::/0'));
    // IPv4-mapped IPv6 is the same visitor as the IPv4 address (A-13).
    ok(Ip::in_range('::ffff:203.0.113.7', '203.0.113.0/24'), 'mapped IPv6 is treated as its IPv4 address');
    no(Ip::in_range('2001:db8::7', '203.0.113.0/24'), 'real IPv6 never matches an IPv4 range');
});

t('in_range(): bad CIDR and junk never match (and never error)', function () {
    foreach (array('203.0.113.0/33', '203.0.113.0/-1', '203.0.113.0/abc', '203.0.113.0/', 'not-an-ip/24', '', '  ', '2001:db8::/129', '203.0.113.300', '/24') as $range) {
        no(Ip::in_range('203.0.113.7', $range), 'range ' . var_export($range, true));
    }
    no(Ip::in_range('garbage', '0.0.0.0/0'), 'invalid IP');
    no(Ip::in_range('', '0.0.0.0/0'));
    ok(Ip::in_range('203.0.113.7', ' 203.0.113.0/24 '), 'whitespace around the range is trimmed');
});

t('in_ranges(): any match, empty list never', function () {
    ok(Ip::in_ranges('198.51.100.9', array('203.0.113.0/24', 'junk', '198.51.100.0/24')));
    no(Ip::in_ranges('198.51.100.9', array()));
    no(Ip::in_ranges('198.51.100.9', array('junk', '203.0.113.0/24')));
});

t('client(): remote_addr mode ignores every forwarding header', function () {
    set_settings(array('ip_source' => 'remote_addr', 'trusted_proxies' => "203.0.113.1"));
    as_ip('203.0.113.1', array('HTTP_X_FORWARDED_FOR' => '198.51.100.66', 'HTTP_CF_CONNECTING_IP' => '198.51.100.77', 'HTTP_X_REAL_IP' => '198.51.100.88', 'HTTP_CLIENT_IP' => '198.51.100.99'));
    eq('203.0.113.1', Ip::client());
});

t('client(): cloudflare mode trusts CF-Connecting-IP only from Cloudflare ranges', function () {
    set_settings(array('ip_source' => 'cloudflare'));
    as_ip('173.245.48.10', array('HTTP_CF_CONNECTING_IP' => '198.51.100.5'));
    eq('198.51.100.5', Ip::client(), 'from a Cloudflare edge');

    as_ip('203.0.113.9', array('HTTP_CF_CONNECTING_IP' => '198.51.100.5'));
    eq('203.0.113.9', Ip::client(), 'spoofed header from a non-Cloudflare address');

    as_ip('2606:4700::1', array('HTTP_CF_CONNECTING_IP' => '2001:db8::5'));
    eq('2001:db8::5', Ip::client(), 'IPv6 edge');

    as_ip('173.245.48.10', array('HTTP_CF_CONNECTING_IP' => 'not-an-ip'));
    eq('173.245.48.10', Ip::client(), 'invalid header value falls back to REMOTE_ADDR');

    as_ip('173.245.48.10', array('HTTP_CF_CONNECTING_IP' => '198.51.100.5', 'HTTP_X_FORWARDED_FOR' => '198.51.100.99'));
    eq('198.51.100.5', Ip::client(), 'X-Forwarded-For is not used in Cloudflare mode');
});

t('client(): proxy mode walks X-Forwarded-For from the right, only via trusted proxies', function () {
    set_settings(array('ip_source' => 'proxy', 'trusted_proxies' => "10.0.0.0/8\n192.0.2.10"));

    as_ip('10.0.0.5', array('HTTP_X_FORWARDED_FOR' => '198.51.100.1, 203.0.113.50, 192.0.2.10'));
    eq('203.0.113.50', Ip::client(), 'rightmost untrusted hop');

    as_ip('10.0.0.5', array('HTTP_X_FORWARDED_FOR' => '6.6.6.6, 203.0.113.50'));
    eq('203.0.113.50', Ip::client(), 'a forged leftmost entry is ignored');

    as_ip('203.0.113.200', array('HTTP_X_FORWARDED_FOR' => '198.51.100.1'));
    eq('203.0.113.200', Ip::client(), 'header ignored from an untrusted connection');

    as_ip('10.0.0.5', array('HTTP_X_FORWARDED_FOR' => '198.51.100.1, garbage'));
    eq('10.0.0.5', Ip::client(), 'invalid hop: fall back to REMOTE_ADDR');

    as_ip('10.0.0.5', array('HTTP_X_FORWARDED_FOR' => '10.0.0.9, 192.0.2.10'));
    eq('10.0.0.5', Ip::client(), 'only trusted hops: REMOTE_ADDR');

    as_ip('10.0.0.5');
    unset($_SERVER['HTTP_X_FORWARDED_FOR']);
    Ip::reset();
    eq('10.0.0.5', Ip::client(), 'no header');
});

t('client(): invalid REMOTE_ADDR gives 0.0.0.0', function () {
    set_settings(array('ip_source' => 'remote_addr'));
    as_ip('not-an-ip');
    eq('0.0.0.0', Ip::client());
});

t('client(): authlify_client_ip filter result must still be a valid IP', function () {
    set_settings(array('ip_source' => 'remote_addr'));
    add_test_filter('authlify_client_ip', function () {
        return '<script>';
    });
    as_ip('203.0.113.4');
    eq('0.0.0.0', Ip::client());
});

t('subnet(): /24 for IPv4, /64 for IPv6, empty for junk', function () {
    eq('203.0.113.0/24', Ip::subnet('203.0.113.77'));
    eq('2001:db8:1:2::/64', Ip::subnet('2001:db8:1:2:3:4:5:6'));
    eq('', Ip::subnet('nope'));
});

t('anonymize(): last octet (IPv4) / interface bits (IPv6, core rule) zeroed, junk unchanged', function () {
    eq('203.0.113.0', Ip::anonymize('203.0.113.77'));
    eq('2001:db8:1:2::', Ip::anonymize('2001:db8:1:2:3:4:5:6'), 'core wp_privacy_anonymize_ip keeps the /64');
    eq('nope', Ip::anonymize('nope'));
});

t('valid() and is_private()', function () {
    ok(Ip::valid('::1'));
    no(Ip::valid('1.2.3'));
    no(Ip::valid(123));
    ok(Ip::is_private('10.1.1.1'));
    ok(Ip::is_private('127.0.0.1'));
    no(Ip::is_private('8.8.8.8'));
});

t('A-13: normalize(): ports, brackets and IPv4-mapped IPv6', function () {
    eq('203.0.113.9', Ip::normalize('::ffff:203.0.113.9'));
    eq('203.0.113.9', Ip::normalize('203.0.113.9:4711'));
    eq('2001:db8::5', Ip::normalize('[2001:db8::5]:443'));
    eq('2001:db8::5', Ip::normalize('[2001:db8::5]'));
    eq('2001:db8::5', Ip::normalize('2001:db8::5'), 'plain IPv6 unchanged');
    eq('junk', Ip::normalize(' junk '));
});

t('A-13: client() normalises REMOTE_ADDR and trusted X-Forwarded-For hops', function () {
    set_settings(array('ip_source' => 'remote_addr', 'trusted_proxies' => ''));
    as_ip('::ffff:203.0.113.9');
    eq('203.0.113.9', Ip::client());
    set_settings(array('ip_source' => 'proxy', 'trusted_proxies' => '10.0.0.0/8'));
    as_ip('::ffff:10.0.0.5', array('HTTP_X_FORWARDED_FOR' => '198.51.100.7:51234'));
    eq('198.51.100.7', Ip::client(), 'mapped proxy address is trusted; hop port stripped');
    as_ip('10.0.0.5', array('HTTP_X_FORWARDED_FOR' => '[2001:db8::5]:443, 10.0.0.9:80'));
    eq('2001:db8::5', Ip::client());
    ok(Ip::in_range('::ffff:192.0.2.1', '192.0.2.0/24'), 'in_range() sees the IPv4 address');
});

t('network(): /24 for IPv4, /48 for IPv6', function () {
    eq('192.0.2.0/24', Ip::network('192.0.2.77'));
    eq('2001:db8:5::/48', Ip::network('2001:db8:5:ff::1'));
    eq('', Ip::network('junk'));
});
