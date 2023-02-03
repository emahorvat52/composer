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

namespace Composer\Test\Repository\Vcs;

use Composer\Config;
use Composer\Repository\Vcs\GitBitbucketDriver;
use Composer\Test\TestCase;
use Composer\Util\Filesystem;
use Composer\Util\ProcessExecutor;
use Composer\Util\Http\Response;

/**
 * @group bitbucket
 */
class GitBitbucketDriverTest extends TestCase
{
    /** @var \Composer\IO\IOInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $io;
    /** @var Config */
    private $config;
    /** @var \Composer\Util\HttpDownloader&\PHPUnit\Framework\MockObject\MockObject */
    private $httpDownloader;
    /** @var string */
    private $home;

    protected function setUp(): void
    {
        $this->io = $this->getMockBuilder('Composer\IO\IOInterface')->getMock();

        $this->home = self::getUniqueTmpDirectory();

        $this->config = new Config();
        $this->config->merge([
            'config' => [
                'home' => $this->home,
            ],
        ]);

        $this->httpDownloader = $this->getMockBuilder('Composer\Util\HttpDownloader')
            ->disableOriginalConstructor()
            ->getMock();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $fs = new Filesystem;
        $fs->removeDirectory($this->home);
    }

    /**
     * @param  array<string, mixed> $repoConfig
     *
     * @phpstan-param array{url: string}&array<string, mixed> $repoConfig
     */
    private function getDriver(array $repoConfig): GitBitbucketDriver
    {
        $driver = new GitBitbucketDriver(
            $repoConfig,
            $this->io,
            $this->config,
            $this->httpDownloader,
            new ProcessExecutor($this->io)
        );

        $driver->initialize();

        return $driver;
    }

    public function testGetRootIdentifierWrongScmType(): void
    {
        self::expectException('RuntimeException');
        self::expectExceptionMessage('https://bitbucket.org/user/repo.git does not appear to be a git repository, use https://bitbucket.org/user/repo but remember that Bitbucket no longer supports the mercurial repositories. https://bitbucket.org/blog/sunsetting-mercurial-support-in-bitbucket');

        $this->httpDownloader->expects($this->once())
            ->method('get')
            ->with(
                $url = 'https://api.bitbucket.org/2.0/repositories/user/repo?fields=-project%2C-owner',
                []
            )
            ->willReturn(
                new Response(['url' => $url], 200, [], '{"scm":"hg","website":"","has_wiki":false,"name":"repo","links":{"branches":{"href":"https:\/\/api.bitbucket.org\/2.0\/repositories\/user\/repo\/refs\/branches"},"tags":{"href":"https:\/\/api.bitbucket.org\/2.0\/repositories\/user\/repo\/refs\/tags"},"clone":[{"href":"https:\/\/user@bitbucket.org\/user\/repo","name":"https"},{"href":"ssh:\/\/hg@bitbucket.org\/user\/repo","name":"ssh"}],"html":{"href":"https:\/\/bitbucket.org\/user\/repo"}},"language":"php","created_on":"2015-02-18T16:22:24.688+00:00","updated_on":"2016-05-17T13:20:21.993+00:00","is_private":true,"has_issues":false}')
            );

        $driver = $this->getDriver(['url' => 'https://bitbucket.org/user/repo.git']);

        $driver->getRootIdentifier();
    }

    public function testDriver(): GitBitbucketDriver
    {
        $driver = $this->getDriver(['url' => 'https://bitbucket.org/user/repo.git']);

        $urls = [
            'https://api.bitbucket.org/2.0/repositories/user/repo?fields=-project%2C-owner',
            'https://api.bitbucket.org/2.0/repositories/user/repo/refs/tags?pagelen=100&fields=values.name%2Cvalues.target.hash%2Cnext&sort=-target.date',
            'https://api.bitbucket.org/2.0/repositories/user/repo/refs/branches?pagelen=100&fields=values.name%2Cvalues.target.hash%2Cvalues.heads%2Cnext&sort=-target.date',
            'https://api.bitbucket.org/2.0/repositories/user/repo/src/main/composer.json',
            'https://api.bitbucket.org/2.0/repositories/user/repo/commit/main?fields=date',
        ];
        $this->httpDownloader->expects($this->any())
            ->method('get')
            ->withConsecutive(
                [
                    $urls[0], [],
                ],
                [
                    $urls[1], [],
                ],
                [
                    $urls[2], [],
                ],
                [
                    $urls[3], [],
                ],
                [
                    $urls[4], [],
                ]
            )
            ->willReturnOnConsecutiveCalls(
                new Response(['url' => $urls[0]], 200, [], '{"mainbranch": {"name": "main"}, "scm":"git","website":"","has_wiki":false,"name":"repo","links":{"branches":{"href":"https:\/\/api.bitbucket.org\/2.0\/repositories\/user\/repo\/refs\/branches"},"tags":{"href":"https:\/\/api.bitbucket.org\/2.0\/repositories\/user\/repo\/refs\/tags"},"clone":[{"href":"https:\/\/user@bitbucket.org\/user\/repo.git","name":"https"},{"href":"ssh:\/\/git@bitbucket.org\/user\/repo.git","name":"ssh"}],"html":{"href":"https:\/\/bitbucket.org\/user\/repo"}},"language":"php","created_on":"2015-02-18T16:22:24.688+00:00","updated_on":"2016-05-17T13:20:21.993+00:00","is_private":true,"has_issues":false}'),
                new Response(['url' => $urls[1]], 200, [], '{"values":[{"name":"1.0.1","target":{"hash":"9b78a3932143497c519e49b8241083838c8ff8a1"}},{"name":"1.0.0","target":{"hash":"d3393d514318a9267d2f8ebbf463a9aaa389f8eb"}}]}'),
                new Response(['url' => $urls[2]], 200, [], '{"values":[{"name":"main","target":{"hash":"937992d19d72b5116c3e8c4a04f960e5fa270b22"}}]}'),
                new Response(['url' => $urls[3]], 200, [], '{"name": "user/repo","description": "test repo","license": "GPL","authors": [{"name": "Name","email": "local@domain.tld"}],"require": {"creator/package": "^1.0"},"require-dev": {"phpunit/phpunit": "~4.8"}}'),
                new Response(['url' => $urls[4]], 200, [], '{"date": "2016-05-17T13:19:52+00:00"}')
            );

        $this->assertEquals(
            'main',
            $driver->getRootIdentifier()
        );

        $this->assertEquals(
            [
                '1.0.1' => '9b78a3932143497c519e49b8241083838c8ff8a1',
                '1.0.0' => 'd3393d514318a9267d2f8ebbf463a9aaa389f8eb',
            ],
            $driver->getTags()
        );

        $this->assertEquals(
            [
                'main' => '937992d19d72b5116c3e8c4a04f960e5fa270b22',
            ],
            $driver->getBranches()
        );

        $this->assertEquals(
            [
                'name' => 'user/repo',
                'description' => 'test repo',
                'license' => 'GPL',
                'authors' => [
                    [
                        'name' => 'Name',
                        'email' => 'local@domain.tld',
                    ],
                ],
                'require' => [
                    'creator/package' => '^1.0',
                ],
                'require-dev' => [
                    'phpunit/phpunit' => '~4.8',
                ],
                'time' => '2016-05-17T13:19:52+00:00',
                'support' => [
                    'source' => 'https://bitbucket.org/user/repo/src/937992d19d72b5116c3e8c4a04f960e5fa270b22/?at=main',
                ],
                'homepage' => 'https://bitbucket.org/user/repo',
            ],
            $driver->getComposerInformation('main')
        );

        return $driver;
    }

    /**
     * @depends testDriver
     */
    public function testGetParams(\Composer\Repository\Vcs\VcsDriverInterface $driver): void
    {
        $url = 'https://bitbucket.org/user/repo.git';

        $this->assertEquals($url, $driver->getUrl());

        $this->assertEquals(
            [
                'type' => 'zip',
                'url' => 'https://bitbucket.org/user/repo/get/reference.zip',
                'reference' => 'reference',
                'shasum' => '',
            ],
            $driver->getDist('reference')
        );

        $this->assertEquals(
            ['type' => 'git', 'url' => $url, 'reference' => 'reference'],
            $driver->getSource('reference')
        );
    }

    public function testInitializeInvalidRepositoryUrl(): void
    {
        $this->expectException('\InvalidArgumentException');

        $driver = $this->getDriver(['url' => 'https://bitbucket.org/acme']);
        $driver->initialize();
    }

    public function testSupports(): void
    {
        $this->assertTrue(
            GitBitbucketDriver::supports($this->io, $this->config, 'https://bitbucket.org/user/repo.git')
        );

        // should not be changed, see https://github.com/composer/composer/issues/9400
        $this->assertFalse(
            GitBitbucketDriver::supports($this->io, $this->config, 'git@bitbucket.org:user/repo.git')
        );

        $this->assertFalse(
            GitBitbucketDriver::supports($this->io, $this->config, 'https://github.com/user/repo.git')
        );
    }
}
