<?php

/*
 * This file is part of Composer.
 *
 * (c) Nils Adermann <naderman@naderman.de>
 *     Jordi Boggiano <j.boggiano@seld.be>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Composer\Test\Util;

use Composer\Util\NoProxyPattern;
use Composer\Test\TestCase;

class NoProxyPatternTest extends TestCase
{
    /**
     * @dataProvider dataHostName
     *
     * @param string $noproxy
     * @param string $url
     * @param bool   $expected
     */
    public function testHostName(string $noproxy, string $url, bool $expected): void
    {
        $matcher = new NoProxyPattern($noproxy);
        $url = $this->getUrl($url);
        $this->assertEquals($expected, $matcher->test($url));
    }

    public function dataHostName(): array
    {
        $noproxy = 'foobar.com, .barbaz.net';

        // noproxy, url, expected
        return array(
            'match as foobar.com' => array($noproxy, 'foobar.com', true),
            'match foobar.com' => array($noproxy, 'www.foobar.com', true),
            'no match foobar.com' => array($noproxy, 'foofoobar.com', false),
            'match .barbaz.net 1' => array($noproxy, 'barbaz.net', true),
            'match .barbaz.net 2' => array($noproxy, 'www.barbaz.net', true),
            'no match .barbaz.net' => array($noproxy, 'barbarbaz.net', false),
            'no match wrong domain' => array($noproxy, 'barbaz.com', false),
            'no match FQDN' => array($noproxy, 'foobar.com.', false),
        );
    }

    /**
     * @dataProvider dataIpAddress
     *
     * @param string $noproxy
     * @param string $url
     * @param bool   $expected
     */
    public function testIpAddress(string $noproxy, string $url, bool $expected): void
    {
        $matcher = new NoProxyPattern($noproxy);
        $url = $this->getUrl($url);
        $this->assertEquals($expected, $matcher->test($url));
    }

    public function dataIpAddress(): array
    {
        $noproxy = '192.168.1.1, 2001:db8::52:0:1';

        // noproxy, url, expected
        return array(
            'match exact IPv4' => array($noproxy, '192.168.1.1', true),
            'no match IPv4' => array($noproxy, '192.168.1.4', false),
            'match exact IPv6' => array($noproxy, '[2001:db8:0:0:0:52:0:1]', true),
            'no match IPv6' => array($noproxy, '[2001:db8:0:0:0:52:0:2]', false),
            'match mapped IPv4' => array($noproxy, '[::FFFF:C0A8:0101]', true),
            'no match mapped IPv4' => array($noproxy, '[::FFFF:C0A8:0104]', false),
        );
    }

    /**
     * @dataProvider dataIpRange
     *
     * @param string $noproxy
     * @param string $url
     * @param bool   $expected
     */
    public function testIpRange(string $noproxy, string $url, bool $expected): void
    {
        $matcher = new NoProxyPattern($noproxy);
        $url = $this->getUrl($url);
        $this->assertEquals($expected, $matcher->test($url));
    }

    public function dataIpRange(): array
    {
        $noproxy = '10.0.0.0/30, 2002:db8:a::45/121';

        // noproxy, url, expected
        return array(
            'match IPv4/CIDR' => array($noproxy, '10.0.0.2', true),
            'no match IPv4/CIDR' => array($noproxy, '10.0.0.4', false),
            'match IPv6/CIDR' => array($noproxy, '[2002:db8:a:0:0:0:0:7f]', true),
            'no match IPv6' => array($noproxy, '[2002:db8:a:0:0:0:0:ff]', false),
            'match mapped IPv4' => array($noproxy, '[::FFFF:0A00:0002]', true),
            'no match mapped IPv4' => array($noproxy, '[::FFFF:0A00:0004]', false),
        );
    }

    /**
     * @dataProvider dataPort
     *
     * @param string $noproxy
     * @param string $url
     * @param bool   $expected
     */
    public function testPort(string $noproxy, string $url, bool $expected): void
    {
        $matcher = new NoProxyPattern($noproxy);
        $url = $this->getUrl($url);
        $this->assertEquals($expected, $matcher->test($url));
    }

    public function dataPort(): array
    {
        $noproxy = '192.168.1.2:81, 192.168.1.3:80, [2001:db8::52:0:2]:443, [2001:db8::52:0:3]:80';

        // noproxy, url, expected
        return array(
            'match IPv4 port' => array($noproxy, '192.168.1.3', true),
            'no match IPv4 port' => array($noproxy, '192.168.1.2', false),
            'match IPv6 port' => array($noproxy, '[2001:db8::52:0:3]', true),
            'no match IPv6 port' => array($noproxy, '[2001:db8::52:0:2]', false),
        );
    }

    /**
     * Appends a scheme to the test url if it is missing
     *
     * @param string $url
     *
     * @return string
     */
    private function getUrl(string $url): string
    {
        if (parse_url($url, PHP_URL_SCHEME)) {
            return $url;
        }

        $scheme = 'http';

        if (strpos($url, '[') !== 0 && strrpos($url, ':') !== false) {
            list(, $port) = explode(':', $url);

            if ($port === '443') {
                $scheme = 'https';
            }
        }

        return sprintf('%s://%s', $scheme, $url);
    }
}
