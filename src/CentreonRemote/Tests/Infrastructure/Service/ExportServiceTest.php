<?php
/*
 * Copyright 2005 - 2019 Centreon (https://www.centreon.com/)
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 *
 * For more information : contact@centreon.com
 *
 */

namespace CentreonRemote\Tests\Infrastructure\Service;

use PHPUnit\Framework\TestCase;
use Pimple\Container;
use Pimple\Psr11\Container as ContainerWrap;
use CentreonRemote\Infrastructure\Service\ExportService;
use CentreonRemote\Infrastructure\Service\ExporterService;
use CentreonRemote\Infrastructure\Service\ExporterCacheService;
use CentreonRemote\Infrastructure\Export\ExportCommitment;
use CentreonRemote\Domain\Exporter\ConfigurationExporter;
use CentreonClapi\CentreonACL;
use Centreon\Test\Mock;
use Vfs\FileSystem;
use Vfs\Node\Directory;
use Vfs\Node\File;
use Centreon\Test\Traits\TestCaseExtensionTrait;

/**
 * @group CentreonRemote
 */
class ExportServiceTest extends TestCase
{

    use TestCaseExtensionTrait;

    private $aclReload = false;

    protected function setUp()
    {
        $container = new Container;

        // Exporter
        $container['centreon_remote.exporter'] = $this->getMockBuilder(ExporterService::class)
            ->disableOriginalConstructor()
            ->setMethods([
                'get',
            ])
            ->getMock();

        $container['centreon_remote.exporter']->method('get')
            ->will($this->returnCallback(function () {
                return [
                    'name' => ConfigurationExporter::getName(),
                    'classname' => ConfigurationExporter::class,
                    'factory' => function () {
                        return $this->getMockBuilder(ConfigurationExporter::class)
                            ->disableOriginalConstructor()
                            ->getMock();
                    },
                ];
            }));

        // Cache
        $container['centreon_remote.exporter.cache'] = $this->getMockBuilder(ExporterCacheService::class)
            ->getMock();

        // ACL
        $container['centreon.acl'] = $this->getMockBuilder(CentreonACL::class)
            ->disableOriginalConstructor()
            ->getMock();

        $container['centreon.acl']->method('reload')
            ->will($this->returnCallback(function () {
                $this->aclReload = true;
            }));

        // DB service
        $container[\Centreon\ServiceProvider::CENTREON_DB_MANAGER] = new Mock\CentreonDBManagerService;
        $container[\Centreon\ServiceProvider::CENTREON_DB_MANAGER]
            ->addResultSet(
                "SELECT * FROM informations WHERE `key` = :key LIMIT 1",
                [[
                    'key' => 'version',
                    'value' => 'x.y',
                ]]
            )
            ->addResultSet(
                "DELETE FROM acl_resources_hc_relations "
                . "WHERE hc_id NOT IN (SELECT t2.hc_id FROM hostcategories AS t2)",
                []
            )
            ->addResultSet(
                "DELETE FROM acl_resources_hg_relations "
                . "WHERE hg_hg_id NOT IN (SELECT t2.hg_id FROM hostgroup AS t2)",
                []
            )
            ->addResultSet(
                "DELETE FROM acl_resources_hostex_relations "
                . "WHERE host_host_id NOT IN (SELECT t2.host_id FROM host AS t2)",
                []
            )
            ->addResultSet(
                "DELETE FROM acl_resources_host_relations "
                . "WHERE host_host_id NOT IN (SELECT t2.host_id FROM host AS t2)",
                []
            )
            ->addResultSet(
                "DELETE FROM acl_resources_meta_relations "
                . "WHERE meta_id NOT IN (SELECT t2.meta_id FROM meta_service AS t2)",
                []
            )
            ->addResultSet(
                "DELETE FROM acl_resources_poller_relations "
                . "WHERE poller_id NOT IN (SELECT t2.id FROM nagios_server AS t2)",
                []
            )
            ->addResultSet(
                "DELETE FROM acl_resources_sc_relations "
                . "WHERE sc_id NOT IN (SELECT t2.sc_id FROM service_categories AS t2)",
                []
            )
            ->addResultSet(
                "DELETE FROM acl_resources_service_relations "
                . "WHERE service_service_id NOT IN (SELECT t2.service_id FROM service AS t2)",
                []
            )
            ->addResultSet(
                "DELETE FROM acl_resources_sg_relations "
                . "WHERE sg_id NOT IN (SELECT t2.sg_id FROM servicegroup AS t2)",
                []
            );

        // mount VFS
        $this->fs = FileSystem::factory('vfs://');
        $this->fs->mount();
        $this->fs->get('/')->add('export', new Directory);

        // Export
        $this->export = new ExportService(new ContainerWrap($container));
    }

    public function tearDown()
    {
        // unmount VFS
        $this->fs->unmount();
    }

    /**
     * @covers \CentreonRemote\Infrastructure\Service\ExportService::__construct
     */
    public function testConstruct()
    {
        $this->assertAttributeInstanceOf(ExporterService::class, 'exporter', $this->export);
        $this->assertAttributeInstanceOf(ExporterCacheService::class, 'cache', $this->export);
        $this->assertAttributeInstanceOf(CentreonACL::class, 'acl', $this->export);
        $this->assertAttributeInstanceOf(
            \Centreon\Infrastructure\Service\CentreonDBManagerService::class,
            'db',
            $this->export
        );

        $this->assertAttributeEquals('x.y', 'version', $this->export);
    }

    /**
     * @covers \CentreonRemote\Infrastructure\Service\ExportService::export
     */
    public function testExport()
    {
        $path = "vfs://export";

        $this->fs->get('/export/')->add('test.txt', new File(''));

        $commitment = new ExportCommitment(1, [2, 3], null, null, $path, [
            ConfigurationExporter::class,
        ]);

        $this->export->export($commitment);

        // @todo replace system('rm -rf vfs://...')
        // $this->assertFileNotExists("{$path}/test.txt");

        $this->assertFileExists("{$path}/manifest.json");
    }

    /**
     * @covers \CentreonRemote\Infrastructure\Service\ExportService::import
     */
    public function testImport()
    {
        $path = "vfs://export";

        // missing export path
        $this->export->import(new ExportCommitment(null, null, null, null, "{$path}/not-found", [
            ConfigurationExporter::class,
        ]));

        $manifest = '{
    "date": "Tuesday 23rd of July 2019 11:22:19 AM",
    "pollers": [],
    "import": {},
    "remote_server": 1,
    "version": "x.y"
}';

        $this->fs->get('/export/')->add('manifest.json', new File($manifest));

        $this->export->import(new ExportCommitment(null, null, null, null, $path, [
            ConfigurationExporter::class,
        ]));

        $this->assertTrue(true);
    }

    /**
     * @covers \CentreonRemote\Infrastructure\Service\ExportService::refreshAcl
     */
    public function testRefreshAcl()
    {
        $this->invokeMethod($this->export, 'refreshAcl');

        $this->assertTrue($this->aclReload);
    }
}
