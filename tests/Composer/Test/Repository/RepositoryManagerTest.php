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

namespace Composer\Test\Repository;

use Composer\Repository\RepositoryManager;
use Composer\Test\TestCase;
use Composer\Util\Filesystem;

class RepositoryManagerTest extends TestCase
{
    /** @var string */
    protected $tmpdir;

    public function setUp(): void
    {
        $this->tmpdir = $this->getUniqueTmpDirectory();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        if (is_dir($this->tmpdir)) {
            $fs = new Filesystem();
            $fs->removeDirectory($this->tmpdir);
        }
    }

    public function testPrepend(): void
    {
        $rm = new RepositoryManager(
            $this->getMockBuilder('Composer\IO\IOInterface')->getMock(),
            $this->getMockBuilder('Composer\Config')->getMock(),
            $this->getMockBuilder('Composer\Util\HttpDownloader')->disableOriginalConstructor()->getMock(),
            $this->getMockBuilder('Composer\EventDispatcher\EventDispatcher')->disableOriginalConstructor()->getMock()
        );

        $repository1 = $this->getMockBuilder('Composer\Repository\RepositoryInterface')->getMock();
        $repository2 = $this->getMockBuilder('Composer\Repository\RepositoryInterface')->getMock();
        $rm->addRepository($repository1);
        $rm->prependRepository($repository2);

        $this->assertEquals(array($repository2, $repository1), $rm->getRepositories());
    }

    /**
     * @dataProvider provideRepoCreationTestCases
     *
     * @param string               $type
     * @param array<string, mixed> $options
     * @param class-string<\Throwable>|null $exception
     */
    public function testRepoCreation($type, $options, ?string $exception = null): void
    {
        if ($exception !== null) {
            self::expectException($exception);
        }

        $rm = new RepositoryManager(
            $this->getMockBuilder('Composer\IO\IOInterface')->getMock(),
            $config = $this->getMockBuilder('Composer\Config')->onlyMethods(array('get'))->getMock(),
            $this->getMockBuilder('Composer\Util\HttpDownloader')->disableOriginalConstructor()->getMock(),
            $this->getMockBuilder('Composer\EventDispatcher\EventDispatcher')->disableOriginalConstructor()->getMock()
        );

        $tmpdir = $this->tmpdir;
        $config
            ->expects($this->any())
            ->method('get')
            ->will($this->returnCallback(function ($arg) use ($tmpdir): ?string {
                return 'cache-repo-dir' === $arg ? $tmpdir : null;
            }))
        ;

        $rm->setRepositoryClass('composer', 'Composer\Repository\ComposerRepository');
        $rm->setRepositoryClass('vcs', 'Composer\Repository\VcsRepository');
        $rm->setRepositoryClass('package', 'Composer\Repository\PackageRepository');
        $rm->setRepositoryClass('pear', 'Composer\Repository\PearRepository');
        $rm->setRepositoryClass('git', 'Composer\Repository\VcsRepository');
        $rm->setRepositoryClass('svn', 'Composer\Repository\VcsRepository');
        $rm->setRepositoryClass('perforce', 'Composer\Repository\VcsRepository');
        $rm->setRepositoryClass('hg', 'Composer\Repository\VcsRepository');
        $rm->setRepositoryClass('artifact', 'Composer\Repository\ArtifactRepository');

        $rm->createRepository('composer', array('url' => 'http://example.org'));
        $this->assertInstanceOf('Composer\Repository\RepositoryInterface', $rm->createRepository($type, $options));
    }

    public function provideRepoCreationTestCases(): array
    {
        $cases = array(
            array('composer', array('url' => 'http://example.org')),
            array('vcs', array('url' => 'http://github.com/foo/bar')),
            array('git', array('url' => 'http://github.com/foo/bar')),
            array('git', array('url' => 'git@example.org:foo/bar.git')),
            array('svn', array('url' => 'svn://example.org/foo/bar')),
            array('pear', array('url' => 'http://pear.example.org/foo'), 'InvalidArgumentException'),
            array('package', array('package' => array())),
            array('invalid', array(), 'InvalidArgumentException'),
        );

        if (class_exists('ZipArchive')) {
            $cases[] = array('artifact', array('url' => '/path/to/zips'));
        }

        return $cases;
    }

    public function testFilterRepoWrapping(): void
    {
        $rm = new RepositoryManager(
            $this->getMockBuilder('Composer\IO\IOInterface')->getMock(),
            $config = $this->getMockBuilder('Composer\Config')->onlyMethods(array('get'))->getMock(),
            $this->getMockBuilder('Composer\Util\HttpDownloader')->disableOriginalConstructor()->getMock(),
            $this->getMockBuilder('Composer\EventDispatcher\EventDispatcher')->disableOriginalConstructor()->getMock()
        );

        $rm->setRepositoryClass('path', 'Composer\Repository\PathRepository');
        /** @var \Composer\Repository\FilterRepository $repo */
        $repo = $rm->createRepository('path', array('type' => 'path', 'url' => __DIR__, 'only' => array('foo/bar')));

        $this->assertInstanceOf('Composer\Repository\FilterRepository', $repo);
        $this->assertInstanceOf('Composer\Repository\PathRepository', $repo->getRepository());
    }
}
