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

namespace Composer\Util;

use Composer\Package\PackageInterface;
use Composer\Package\RootPackageInterface;

class PackageSorter
{
    /**
     * Returns the most recent version of a set of packages
     *
     * This is ideally the default branch version, or failing that it will return the package with the highest version
     *
     * @template T of PackageInterface
     * @param array<T> $packages
     * @return ($packages is non-empty-array<T> ? T : T|null)
     */
    public static function getMostCurrentVersion(array $packages): ?PackageInterface
    {
        return array_reduce($packages, function ($carry, $pkg) {
            if ($carry === null) {
                return $pkg;
            }

            if ($pkg->isDefaultBranch()) {
                return $pkg;
            }

            if (!$carry->isDefaultBranch() && version_compare($carry->getVersion(), $pkg->getVersion(), '<')) {
                return $pkg;
            }

            return $carry;
        });
    }

    /**
     * Sorts packages by name
     *
     * @template T of PackageInterface
     * @param array<T> $packages
     * @return array<T>
     */
    public static function sortPackagesAlphabetically(array $packages): array
    {
        usort($packages, static function (PackageInterface $a, PackageInterface $b) {
            return $a->getName() <=> $b->getName();
        });

        return $packages;
    }

    /**
     * Sorts packages by dependency weight
     *
     * Packages of equal weight are sorted alphabetically
     *
     * @param  PackageInterface[] $packages
     * @param  array<string, int> $weights Pre-set weights for some packages to give them more (negative number) or less (positive) weight offsets
     * @return PackageInterface[] sorted array
     */
    public static function sortPackages(array $packages, array $weights = array())
    {
        $usageList = array();

        foreach ($packages as $package) {
            $links = $package->getRequires();
            if ($package instanceof RootPackageInterface) {
                $links = array_merge($links, $package->getDevRequires());
            }
            foreach ($links as $link) {
                $target = $link->getTarget();
                $usageList[$target][] = $package->getName();
            }
        }
        $computing = array();
        $computed = array();
        $computeImportance = function ($name) use (&$computeImportance, &$computing, &$computed, $usageList, $weights) {
            // reusing computed importance
            if (isset($computed[$name])) {
                return $computed[$name];
            }

            // canceling circular dependency
            if (isset($computing[$name])) {
                return 0;
            }

            $computing[$name] = true;
            $weight = isset($weights[$name]) ? $weights[$name] : 0;

            if (isset($usageList[$name])) {
                foreach ($usageList[$name] as $user) {
                    $weight -= 1 - $computeImportance($user);
                }
            }

            unset($computing[$name]);
            $computed[$name] = $weight;

            return $weight;
        };

        $weightedPackages = array();

        foreach ($packages as $index => $package) {
            $name = $package->getName();
            $weight = $computeImportance($name);
            $weightedPackages[] = array('name' => $name, 'weight' => $weight, 'index' => $index);
        }

        usort($weightedPackages, function ($a, $b) {
            if ($a['weight'] !== $b['weight']) {
                return $a['weight'] - $b['weight'];
            }

            return strnatcasecmp($a['name'], $b['name']);
        });

        $sortedPackages = array();

        foreach ($weightedPackages as $pkg) {
            $sortedPackages[] = $packages[$pkg['index']];
        }

        return $sortedPackages;
    }
}
