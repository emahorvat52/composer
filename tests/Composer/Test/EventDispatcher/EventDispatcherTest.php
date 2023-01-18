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

namespace Composer\Test\EventDispatcher;

use Composer\EventDispatcher\Event;
use Composer\EventDispatcher\EventDispatcher;
use Composer\Installer\InstallerEvents;
use Composer\Config;
use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Test\TestCase;
use Composer\IO\BufferIO;
use Composer\Script\ScriptEvents;
use Composer\Script\Event as ScriptEvent;
use Composer\Util\ProcessExecutor;
use Composer\Test\Mock\ProcessExecutorMock;
use Composer\Util\Platform;
use Symfony\Component\Console\Output\OutputInterface;

class EventDispatcherTest extends TestCase
{
    public function testListenerExceptionsAreCaught()
    {
        self::expectException('RuntimeException');

        $io = $this->getMockBuilder('Composer\IO\IOInterface')->getMock();
        $dispatcher = $this->getDispatcherStubForListenersTest(array(
            'Composer\Test\EventDispatcher\EventDispatcherTest::call',
        ), $io);

        $io->expects($this->once())
            ->method('isVerbose')
            ->willReturn(0);

        $io->expects($this->atLeast(2))
            ->method('writeError')
            ->withConsecutive(
                ['> Composer\Test\EventDispatcher\EventDispatcherTest::call'],
                ['<error>Script Composer\Test\EventDispatcher\EventDispatcherTest::call handling the post-install-cmd event terminated with an exception</error>']
            );

        $dispatcher->dispatchScript(ScriptEvents::POST_INSTALL_CMD, false);
    }

    /**
     * @dataProvider provideValidCommands
     *
     * @param string $command
     */
    public function testDispatcherCanExecuteSingleCommandLineScript($command)
    {
        $process = $this->getProcessExecutorMock();
        $process->expects(array(
            $command,
        ), true);

        $dispatcher = $this->getMockBuilder('Composer\EventDispatcher\EventDispatcher')
            ->setConstructorArgs(array(
                $this->createComposerInstance(),
                $this->getMockBuilder('Composer\IO\IOInterface')->getMock(),
                $process,
            ))
            ->onlyMethods(array('getListeners'))
            ->getMock();

        $listener = array($command);
        $dispatcher->expects($this->atLeastOnce())
            ->method('getListeners')
            ->will($this->returnValue($listener));

        $dispatcher->dispatchScript(ScriptEvents::POST_INSTALL_CMD, false);
    }

    /**
     * @dataProvider provideDevModes
     *
     * @param bool $devMode
     */
    public function testDispatcherPassDevModeToAutoloadGeneratorForScriptEvents($devMode)
    {
        $composer = $this->createComposerInstance();

        $generator = $this->getGeneratorMockForDevModePassingTest();
        $generator->expects($this->atLeastOnce())
            ->method('setDevMode')
            ->with($devMode);

        $composer->setAutoloadGenerator($generator);

        $package = $this->getMockBuilder('Composer\Package\RootPackageInterface')->getMock();
        $package->method('getScripts')->will($this->returnValue(array('scriptName' => array('scriptName'))));
        $composer->setPackage($package);

        $composer->setRepositoryManager($this->getRepositoryManagerMockForDevModePassingTest());
        $composer->setInstallationManager($this->getMockBuilder('Composer\Installer\InstallationManager')->disableOriginalConstructor()->getMock());

        $dispatcher = new EventDispatcher(
            $composer,
            $this->getMockBuilder('Composer\IO\IOInterface')->getMock(),
            $this->getProcessExecutorMock()
        );

        $event = $this->getMockBuilder('Composer\Script\Event')
            ->disableOriginalConstructor()
            ->getMock();
        $event->method('getName')->will($this->returnValue('scriptName'));
        $event->expects($this->atLeastOnce())
            ->method('isDevMode')
            ->will($this->returnValue($devMode));

        $dispatcher->hasEventListeners($event);
    }

    public function provideDevModes()
    {
        return array(
            array(true),
            array(false),
        );
    }

    /**
     * @return \PHPUnit\Framework\MockObject\MockObject&\Composer\Autoload\AutoloadGenerator
     */
    private function getGeneratorMockForDevModePassingTest()
    {
        $generator = $this->getMockBuilder('Composer\Autoload\AutoloadGenerator')
            ->disableOriginalConstructor()
            ->onlyMethods(array(
                'buildPackageMap',
                'parseAutoloads',
                'createLoader',
                'setDevMode',
            ))
            ->getMock();
        $generator
            ->method('buildPackageMap')
            ->will($this->returnValue(array()));
        $generator
            ->method('parseAutoloads')
            ->will($this->returnValue(array('psr-0' => array(), 'psr-4' => array(), 'classmap' => array(), 'files' => array(), 'exclude-from-classmap' => array(),)));
        $generator
            ->method('createLoader')
            ->will($this->returnValue($this->getMockBuilder('Composer\Autoload\ClassLoader')->getMock()));

        return $generator;
    }

    /**
     * @return \PHPUnit\Framework\MockObject\MockObject&\Composer\Repository\RepositoryManager
     */
    private function getRepositoryManagerMockForDevModePassingTest()
    {
        $rm = $this->getMockBuilder('Composer\Repository\RepositoryManager')
            ->disableOriginalConstructor()
            ->onlyMethods(array('getLocalRepository'))
            ->getMock();

        $repo = $this->getMockBuilder('Composer\Repository\InstalledRepositoryInterface')->getMock();
        $repo
            ->method('getCanonicalPackages')
            ->will($this->returnValue(array()));

        $rm
            ->method('getLocalRepository')
            ->will($this->returnValue($repo));

        return $rm;
    }

    public function testDispatcherRemoveListener()
    {
        $composer = $this->createComposerInstance();

        $composer->setRepositoryManager($this->getRepositoryManagerMockForDevModePassingTest());
        $composer->setInstallationManager($this->getMockBuilder('Composer\Installer\InstallationManager')->disableOriginalConstructor()->getMock());

        $dispatcher = new EventDispatcher(
            $composer,
            $io = new BufferIO('', OutputInterface::VERBOSITY_VERBOSE),
            $this->getProcessExecutorMock()
        );

        $listener = array($this, 'someMethod');
        $listener2 = array($this, 'someMethod2');
        $listener3 = 'Composer\\Test\\EventDispatcher\\EventDispatcherTest::someMethod';

        $dispatcher->addListener('ev1', $listener, 0);
        $dispatcher->addListener('ev1', $listener, 1);
        $dispatcher->addListener('ev1', $listener2, 1);
        $dispatcher->addListener('ev1', $listener3);
        $dispatcher->addListener('ev2', $listener3);
        $dispatcher->addListener('ev2', $listener);
        $dispatcher->dispatch('ev1');
        $dispatcher->dispatch('ev2');

        $expected = '> ev1: Composer\Test\EventDispatcher\EventDispatcherTest->someMethod'.PHP_EOL
            .'> ev1: Composer\Test\EventDispatcher\EventDispatcherTest->someMethod2'.PHP_EOL
            .'> ev1: Composer\Test\EventDispatcher\EventDispatcherTest->someMethod'.PHP_EOL
            .'> ev1: Composer\Test\EventDispatcher\EventDispatcherTest::someMethod'.PHP_EOL
            .'> ev2: Composer\Test\EventDispatcher\EventDispatcherTest::someMethod'.PHP_EOL
            .'> ev2: Composer\Test\EventDispatcher\EventDispatcherTest->someMethod'.PHP_EOL;
        $this->assertEquals($expected, $io->getOutput());

        $dispatcher->removeListener($this);
        $dispatcher->dispatch('ev1');
        $dispatcher->dispatch('ev2');

        $expected .= '> ev1: Composer\Test\EventDispatcher\EventDispatcherTest::someMethod'.PHP_EOL
            .'> ev2: Composer\Test\EventDispatcher\EventDispatcherTest::someMethod'.PHP_EOL;
        $this->assertEquals($expected, $io->getOutput());
    }

    public function testDispatcherCanExecuteCliAndPhpInSameEventScriptStack()
    {
        $process = $this->getProcessExecutorMock();
        $process->expects(array(
            'echo -n foo',
            'echo -n bar',
        ), true);

        $dispatcher = $this->getMockBuilder('Composer\EventDispatcher\EventDispatcher')
            ->setConstructorArgs(array(
                $this->createComposerInstance(),
                $io = new BufferIO('', OutputInterface::VERBOSITY_VERBOSE),
                $process,
            ))
            ->onlyMethods(array(
                'getListeners',
            ))
            ->getMock();

        $listeners = array(
            'echo -n foo',
            'Composer\\Test\\EventDispatcher\\EventDispatcherTest::someMethod',
            'echo -n bar',
        );

        $dispatcher->expects($this->atLeastOnce())
            ->method('getListeners')
            ->will($this->returnValue($listeners));

        $dispatcher->dispatchScript(ScriptEvents::POST_INSTALL_CMD, false);

        $expected = '> post-install-cmd: echo -n foo'.PHP_EOL.
            '> post-install-cmd: Composer\Test\EventDispatcher\EventDispatcherTest::someMethod'.PHP_EOL.
            '> post-install-cmd: echo -n bar'.PHP_EOL;
        $this->assertEquals($expected, $io->getOutput());
    }

    public function testDispatcherCanPutEnv()
    {
        $dispatcher = $this->getMockBuilder('Composer\EventDispatcher\EventDispatcher')
            ->setConstructorArgs(array(
                $this->createComposerInstance(),
                $io = new BufferIO('', OutputInterface::VERBOSITY_VERBOSE),
                $this->getProcessExecutorMock(),
            ))
            ->onlyMethods(array(
                'getListeners',
            ))
            ->getMock();

        $listeners = array(
            '@putenv ABC=123',
            'Composer\\Test\\EventDispatcher\\EventDispatcherTest::getTestEnv',
        );

        $dispatcher->expects($this->atLeastOnce())
            ->method('getListeners')
            ->will($this->returnValue($listeners));

        $dispatcher->dispatchScript(ScriptEvents::POST_INSTALL_CMD, false);

        $expected = '> post-install-cmd: @putenv ABC=123'.PHP_EOL.
            '> post-install-cmd: Composer\Test\EventDispatcher\EventDispatcherTest::getTestEnv'.PHP_EOL;
        $this->assertEquals($expected, $io->getOutput());
    }

    public function testDispatcherAppendsDirBinOnPathForEveryListener()
    {
        $currentDirectoryBkp = getcwd();
        $composerBinDirBkp = Platform::getEnv('COMPOSER_BIN_DIR');
        chdir(__DIR__);
        Platform::putEnv('COMPOSER_BIN_DIR', __DIR__ . '/vendor/bin');

        $dispatcher = $this->getMockBuilder('Composer\EventDispatcher\EventDispatcher')->setConstructorArgs(array(
                $this->createComposerInstance(),
                $io = new BufferIO('', OutputInterface::VERBOSITY_VERBOSE),
                $this->getProcessExecutorMock(),
            ))->onlyMethods(array(
                'getListeners',
            ))->getMock();

        $listeners = array(
            'Composer\\Test\\EventDispatcher\\EventDispatcherTest::createsVendorBinFolderChecksEnvDoesNotContainsBin',
            'Composer\\Test\\EventDispatcher\\EventDispatcherTest::createsVendorBinFolderChecksEnvContainsBin',
        );

        $dispatcher->expects($this->atLeastOnce())->method('getListeners')->will($this->returnValue($listeners));

        $dispatcher->dispatchScript(ScriptEvents::POST_INSTALL_CMD, false);
        rmdir(__DIR__ . '/vendor/bin');
        rmdir(__DIR__ . '/vendor');

        chdir($currentDirectoryBkp);
        if ($composerBinDirBkp) {
            Platform::putEnv('COMPOSER_BIN_DIR', $composerBinDirBkp);
        } else {
            Platform::clearEnv('COMPOSER_BIN_DIR');
        }
    }

    /**
     * @return void
     */
    public static function createsVendorBinFolderChecksEnvDoesNotContainsBin()
    {
        mkdir(__DIR__ . '/vendor/bin', 0700, true);
        $val = getenv('PATH');

        if (!$val) {
            $val = getenv('Path');
        }

        self::assertStringNotContainsString(__DIR__ . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'bin', $val);
    }

    /**
     * @return void
     */
    public static function createsVendorBinFolderChecksEnvContainsBin()
    {
        $val = getenv('PATH');

        if (!$val) {
            $val = getenv('Path');
        }

        self::assertStringContainsString(__DIR__ . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'bin', $val);
    }

    /**
     * @return void
     */
    public static function getTestEnv()
    {
        $val = getenv('ABC');
        if ($val !== '123') {
            throw new \Exception('getenv() did not return the expected value. expected 123 got '. var_export($val, true));
        }
    }

    public function testDispatcherCanExecuteComposerScriptGroups()
    {
        $process = $this->getProcessExecutorMock();
        $process->expects(array(
            'echo -n foo',
            'echo -n baz',
            'echo -n bar',
        ), true);

        $dispatcher = $this->getMockBuilder('Composer\EventDispatcher\EventDispatcher')
            ->setConstructorArgs(array(
                $composer = $this->createComposerInstance(),
                $io = new BufferIO('', OutputInterface::VERBOSITY_VERBOSE),
                $process,
            ))
            ->onlyMethods(array(
                'getListeners',
            ))
            ->getMock();

        $dispatcher->expects($this->atLeastOnce())
            ->method('getListeners')
            ->will($this->returnCallback(function (Event $event) {
                if ($event->getName() === 'root') {
                    return array('@group');
                }

                if ($event->getName() === 'group') {
                    return array('echo -n foo', '@subgroup', 'echo -n bar');
                }

                if ($event->getName() === 'subgroup') {
                    return array('echo -n baz');
                }

                return array();
            }));

        $dispatcher->dispatch('root', new ScriptEvent('root', $composer, $io));
        $expected = '> root: @group'.PHP_EOL.
            '> group: echo -n foo'.PHP_EOL.
            '> group: @subgroup'.PHP_EOL.
            '> subgroup: echo -n baz'.PHP_EOL.
            '> group: echo -n bar'.PHP_EOL;
        $this->assertEquals($expected, $io->getOutput());
    }

    public function testRecursionInScriptsNames()
    {
        $process = $this->getProcessExecutorMock();
        $process->expects(array(
            'echo Hello '.ProcessExecutor::escape('World'),
        ), true);

        $dispatcher = $this->getMockBuilder('Composer\EventDispatcher\EventDispatcher')
            ->setConstructorArgs(array(
                $composer = $this->createComposerInstance(),
                $io = new BufferIO('', OutputInterface::VERBOSITY_VERBOSE),
                $process,
            ))
            ->onlyMethods(array(
                'getListeners',
            ))
            ->getMock();

        $dispatcher->expects($this->atLeastOnce())
            ->method('getListeners')
            ->will($this->returnCallback(function (Event $event) {
                if ($event->getName() === 'hello') {
                    return array('echo Hello');
                }

                if ($event->getName() === 'helloWorld') {
                    return array('@hello World');
                }

                return array();
            }));

        $dispatcher->dispatch('helloWorld', new ScriptEvent('helloWorld', $composer, $io));
        $expected = "> helloWorld: @hello World".PHP_EOL.
            "> hello: echo Hello " .$this->getCmd("'World'").PHP_EOL;

        $this->assertEquals($expected, $io->getOutput());
    }

    public function testDispatcherDetectInfiniteRecursion()
    {
        self::expectException('RuntimeException');

        $dispatcher = $this->getMockBuilder('Composer\EventDispatcher\EventDispatcher')
        ->setConstructorArgs(array(
            $composer = $this->createComposerInstance(),
            $io = $this->getMockBuilder('Composer\IO\IOInterface')->getMock(),
            $this->getProcessExecutorMock(),
        ))
        ->onlyMethods(array(
            'getListeners',
        ))
        ->getMock();

        $dispatcher->expects($this->atLeastOnce())
            ->method('getListeners')
            ->will($this->returnCallback(function (Event $event) {
                if ($event->getName() === 'root') {
                    return array('@recurse');
                }

                if ($event->getName() === 'recurse') {
                    return array('@root');
                }

                return array();
            }));

        $dispatcher->dispatch('root', new ScriptEvent('root', $composer, $io));
    }

    /**
     * @param array<callable|string> $listeners
     *
     * @return \PHPUnit\Framework\MockObject\MockObject&\Composer\EventDispatcher\EventDispatcher
     */
    private function getDispatcherStubForListenersTest($listeners, IOInterface $io)
    {
        $dispatcher = $this->getMockBuilder('Composer\EventDispatcher\EventDispatcher')
            ->setConstructorArgs(array(
                $this->createComposerInstance(),
                $io,
            ))
            ->onlyMethods(array('getListeners'))
            ->getMock();

        $dispatcher->expects($this->atLeastOnce())
            ->method('getListeners')
            ->will($this->returnValue($listeners));

        return $dispatcher;
    }

    public function provideValidCommands()
    {
        return array(
            array('phpunit'),
            array('echo foo'),
            array('echo -n foo'),
        );
    }

    public function testDispatcherOutputsCommand()
    {
        $dispatcher = $this->getMockBuilder('Composer\EventDispatcher\EventDispatcher')
            ->setConstructorArgs(array(
                $this->createComposerInstance(),
                $io = $this->getMockBuilder('Composer\IO\IOInterface')->getMock(),
                new ProcessExecutor($io),
            ))
            ->onlyMethods(array('getListeners'))
            ->getMock();

        $listener = array('echo foo');
        $dispatcher->expects($this->atLeastOnce())
            ->method('getListeners')
            ->will($this->returnValue($listener));

        $io->expects($this->once())
            ->method('writeError')
            ->with($this->equalTo('> echo foo'));

        $io->expects($this->once())
            ->method('writeRaw')
            ->with($this->equalTo('foo'.PHP_EOL), false);

        $dispatcher->dispatchScript(ScriptEvents::POST_INSTALL_CMD, false);
    }

    public function testDispatcherOutputsErrorOnFailedCommand()
    {
        $dispatcher = $this->getMockBuilder('Composer\EventDispatcher\EventDispatcher')
            ->setConstructorArgs(array(
                $this->createComposerInstance(),
                $io = $this->getMockBuilder('Composer\IO\IOInterface')->getMock(),
                new ProcessExecutor,
            ))
            ->onlyMethods(array('getListeners'))
            ->getMock();

        $code = 'exit 1';
        $listener = array($code);
        $dispatcher->expects($this->atLeastOnce())
            ->method('getListeners')
            ->will($this->returnValue($listener));

        $io->expects($this->once())
            ->method('isVerbose')
            ->willReturn(0);

        $io->expects($this->atLeast(2))
            ->method('writeError')
            ->withConsecutive(
                ['> exit 1'],
                ['<error>Script '.$code.' handling the post-install-cmd event returned with error code 1</error>']
            );

        $io->expects($this->once())
            ->method('isInteractive')
            ->willReturn(1);

        self::expectException('RuntimeException');
        $dispatcher->dispatchScript(ScriptEvents::POST_INSTALL_CMD, false);
    }

    public function testDispatcherInstallerEvents()
    {
        $dispatcher = $this->getMockBuilder('Composer\EventDispatcher\EventDispatcher')
            ->setConstructorArgs(array(
                    $this->createComposerInstance(),
                    $this->getMockBuilder('Composer\IO\IOInterface')->getMock(),
                    $this->getProcessExecutorMock(),
                ))
            ->onlyMethods(array('getListeners'))
            ->getMock();

        $dispatcher->expects($this->atLeastOnce())
            ->method('getListeners')
            ->will($this->returnValue(array()));

        $transaction = $this->getMockBuilder('Composer\DependencyResolver\LockTransaction')->disableOriginalConstructor()->getMock();

        $dispatcher->dispatchInstallerEvent(InstallerEvents::PRE_OPERATIONS_EXEC, true, true, $transaction);
    }

    /**
     * @return void
     */
    public static function call()
    {
        throw new \RuntimeException();
    }

    /**
     * @return true
     */
    public static function someMethod()
    {
        return true;
    }

    /**
     * @return true
     */
    public static function someMethod2()
    {
        return true;
    }

    /**
     * @return Composer
     */
    private function createComposerInstance()
    {
        $composer = new Composer;
        $config = new Config();
        $composer->setConfig($config);
        $package = $this->getMockBuilder('Composer\Package\RootPackageInterface')->getMock();
        $composer->setPackage($package);

        return $composer;
    }
}
