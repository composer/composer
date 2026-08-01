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

namespace Composer\Test\DependencyResolver\Operation;

use Composer\DependencyResolver\Operation\UpdateOperation;
use Composer\Package\CompletePackage;
use Composer\Test\TestCase;

class UpdateOperationTest extends TestCase
{
    public function testFormatIncludesReferenceAndMetadataChanges(): void
    {
        $initialPackage = new CompletePackage('vendor/package', '1.0.0.0', '1.0.0');
        $initialPackage->setSourceReference('old-reference');
        $targetPackage = new CompletePackage('vendor/package', '1.0.0.0', '1.0.0');
        $targetPackage->setSourceReference('new-reference');
        $targetPackage->setAbandoned(true);

        self::assertSame(
            'Upgrading <info>vendor/package</info> (<comment>1.0.0 old-reference</comment> => <comment>1.0.0 new-reference</comment>, metadata changes)',
            UpdateOperation::format($initialPackage, $targetPackage)
        );
    }

    public function testFormatDoesNotDescribeMetadataForVersionUpdate(): void
    {
        $initialPackage = new CompletePackage('vendor/package', '1.0.0.0', '1.0.0');
        $targetPackage = new CompletePackage('vendor/package', '2.0.0.0', '2.0.0');
        $targetPackage->setAbandoned(true);

        self::assertSame(
            'Upgrading <info>vendor/package</info> (<comment>1.0.0</comment> => <comment>2.0.0</comment>)',
            UpdateOperation::format($initialPackage, $targetPackage)
        );
    }
}
