<?php

namespace SilverStripe\Dev\State;

use LogicException;
use SilverStripe\Control\Director;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Core\Manifest\ClassLoader;
use SilverStripe\Dev\FixtureFactory;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Dev\YamlFixture;
use SilverStripe\ORM\DataObject;

class FixtureTestState implements TestState
{

    /**
     * @var FixtureFactory[]
     */
    private $fixtureFactories = [];

    /**
     * Called on setup
     *
     * @param SapphireTest $test
     */
    public function setUp(SapphireTest $test)
    {
        if ($this->testNeedsDB($test)) {
            $tmpDB = $test::tempDB();
            if (!$tmpDB->isUsed()) {
                $tmpDB->build();
            }
            DataObject::singleton()->flushCache();

            if (!$tmpDB->hasStarted()) {
                foreach ($test->getRequireDefaultRecordsFrom() as $className) {
                    $instance = singleton($className);
                    if (method_exists($instance, 'requireDefaultRecords')) {
                        $instance->requireDefaultRecords();
                    }
                    if (method_exists($instance, 'augmentDefaultRecords')) {
                        $instance->augmentDefaultRecords();
                    }
                }
                $this->loadFixtures($test);
            }
            $tmpDB->startTransaction();
        }
    }

    /**
     * Called on tear down
     *
     * @param SapphireTest $test
     */
    public function tearDown(SapphireTest $test)
    {
        if ($this->testNeedsDB($test)) {
            $test::tempDB()->rollbackTransaction();
        }
    }

    /**
     * Called once on setup
     *
     * @param string $class Class being setup
     */
    public function setUpOnce($class)
    {
        $this->fixtureFactories[strtolower($class)] = Injector::inst()->create(FixtureFactory::class);
    }

    /**
     * Called once on tear down
     *
     * @param string $class Class being torn down
     */
    public function tearDownOnce($class)
    {
        unset($this->fixtureFactories[strtolower($class)]);
        $class::tempDB()->clearAllData();
    }

    /**
     * @param string $class
     *
     * @return bool|FixtureFactory
     */
    public function getFixtureFactory($class)
    {
        $testClass = strtolower($class);
        if (array_key_exists($testClass, $this->fixtureFactories)) {
            return $this->fixtureFactories[$testClass];
        }
        return false;
    }

    /**
     * @param FixtureFactory $factory
     * @param string $class
     */
    public function setFixtureFactory(FixtureFactory $factory, $class)
    {
        $this->fixtureFactories[strtolower($class)] = $factory;
    }

    /**
     * @param array $fixtures
     *
     * @param SapphireTest $test
     *
     * @return array
     */
    protected function getFixturePaths($fixtures, SapphireTest $test)
    {
        return array_map(function ($fixtureFilePath) use ($test) {
            return $this->resolveFixturePath($fixtureFilePath, $test);
        }, $fixtures);
    }

    /**
     * @param SapphireTest $test
     */
    protected function loadFixtures(SapphireTest $test)
    {
        $fixtures = $test::get_fixture_file();
        $fixtures = is_array($fixtures) ? $fixtures : [$fixtures];
        $paths = $this->getFixturePaths($fixtures, $test);
        foreach ($paths as $fixtureFile) {
            $this->loadFixture($fixtureFile, $test);
        }
    }

    /**
     * @param string $fixtureFile
     * @param SapphireTest $test
     */
    protected function loadFixture($fixtureFile, SapphireTest $test)
    {
        /** @var YamlFixture $fixture */
        $fixture = Injector::inst()->create(YamlFixture::class, $fixtureFile);
        $fixture->writeInto($this->getFixtureFactory(get_class($test)));
    }

    /**
     * Map a fixture path to a physical file
     *
     * @param string $fixtureFilePath
     * @param SapphireTest $test
     *
     * @return string
     */
    protected function resolveFixturePath($fixtureFilePath, SapphireTest $test)
    {
        // Support fixture paths relative to the test class, rather than relative to webroot
        // String checking is faster than file_exists() calls.
        $resolvedPath = realpath($this->getTestAbsolutePath($test) . '/' . $fixtureFilePath);
        if ($resolvedPath) {
            return $resolvedPath;
        }

        // Check if file exists relative to base dir
        $resolvedPath = realpath(Director::baseFolder() . '/' . $fixtureFilePath);
        if ($resolvedPath) {
            return $resolvedPath;
        }

        return $fixtureFilePath;
    }

    /**
     * Useful for writing unit tests without hardcoding folder structures.
     *
     * @param SapphireTest $test
     *
     * @return string Absolute path to current class.
     */
    protected function getTestAbsolutePath(SapphireTest $test)
    {
        $filename = ClassLoader::inst()->getItemPath(get_class($test));
        if (!$filename) {
            throw new LogicException('getItemPath returned null for ' . static::class
                . '. Try adding flush=1 to the test run.');
        }
        return dirname($filename);
    }

    /**
     * @param SapphireTest $test
     *
     * @return bool
     */
    protected function testNeedsDB(SapphireTest $test)
    {
        // test class explicitly enables DB
        if ($test->getUsesDatabase()) {
            return true;
        }

        // presence of fixture file implicitly enables DB
        $fixtures = $test::get_fixture_file();
        if (!empty($fixtures)) {
            return true;
        }

        $annotations = $test->getAnnotations();

        // annotation explicitly disables the DB
        if (array_key_exists('useDatabase', $annotations['method'])
            && $annotations['method']['useDatabase'][0] === 'false') {
            return false;
        }

        // annotation explicitly enables the DB
        if (array_key_exists('useDatabase', $annotations['method'])
            && $annotations['method']['useDatabase'][0] !== 'false') {
            return true;
        }

        return false;
    }
}
