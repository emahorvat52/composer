<?php declare(strict_types=1);

/*
 * This file is part of Composer.
 *
 * (c) Nils Adermann <naderman@naderman.de>
 *     Jordi Boggiano <j.boggiano@seld.be>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Composer\Test\Package\Version;

use Composer\Package\Version\VersionParser;
use Composer\Test\TestCase;

class VersionParserTest extends TestCase
{
    /**
     * @dataProvider provideParseNameVersionPairsData
     *
     * @param string[]                     $pairs
     * @param array<array<string, string>> $result
     */
    public function testParseNameVersionPairs(array $pairs, array $result): void
    {
        $versionParser = new VersionParser();

        $this->assertSame($result, $versionParser->parseNameVersionPairs($pairs));
    }

    public function provideParseNameVersionPairsData(): array
    {
        return array(
            array(array('php:^7.0'), array(array('name' => 'php', 'version' => '^7.0'))),
            array(array('php', '^7.0'), array(array('name' => 'php', 'version' => '^7.0'))),
            array(array('php', 'ext-apcu'), array(array('name' => 'php'), array('name' => 'ext-apcu'))),
            array(array('foo/*', 'bar*', 'acme/baz', '*@dev'), array(array('name' => 'foo/*'), array('name' => 'bar*'), array('name' => 'acme/baz', 'version' => '*@dev'))),
            array(array('php', '*'), array(array('name' => 'php', 'version' => '*'))),
        );
    }

    /**
     * @dataProvider provideIsUpgradeTests
     *
     * @param string $from
     * @param string $to
     * @param bool   $expected
     */
    public function testIsUpgrade(string $from, string $to, bool $expected): void
    {
        $this->assertSame($expected, VersionParser::isUpgrade($from, $to));
    }

    public function provideIsUpgradeTests(): array
    {
        return array(
            array('0.9.0.0', '1.0.0.0', true),
            array('1.0.0.0', '0.9.0.0', false),
            array('1.0.0.0', VersionParser::DEFAULT_BRANCH_ALIAS, true),
            array(VersionParser::DEFAULT_BRANCH_ALIAS, VersionParser::DEFAULT_BRANCH_ALIAS, true),
            array(VersionParser::DEFAULT_BRANCH_ALIAS, '1.0.0.0', false),
            array('1.0.0.0', 'dev-foo', true),
            array('dev-foo', 'dev-foo', true),
            array('dev-foo', '1.0.0.0', true),
        );
    }
}
