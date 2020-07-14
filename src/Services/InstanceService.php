<?php

namespace Agnes\Services;

use Agnes\Models\Connection\Connection;
use Agnes\Models\Filter;
use Agnes\Models\Installation;
use Agnes\Models\Instance;
use Agnes\Models\Task\Deploy;
use Exception;

class InstanceService
{
    /**
     * @var ConfigurationService
     */
    private $configurationService;

    /**
     * @var InstallationService
     */
    private $installationService;

    /**
     * @var Instance[]|null
     */
    private $instancesCache = null;

    /**
     * InstallationService constructor.
     */
    public function __construct(ConfigurationService $configurationService, InstallationService $installationService)
    {
        $this->configurationService = $configurationService;
        $this->installationService = $installationService;
    }

    /**
     * @return Instance[]
     *
     * @throws Exception
     */
    public function getInstancesByFilter(?Filter $filter)
    {
        if (null === $this->instancesCache) {
            $this->instancesCache = $this->loadInstances();
        }

        if (null === $filter) {
            return $this->instancesCache;
        }

        /** @var Instance[] $filteredInstallations */
        $filteredInstallations = [];
        foreach ($this->instancesCache as $installation) {
            if ($filter->instanceMatches($installation)) {
                $filteredInstallations[] = $installation;
            }
        }

        return $filteredInstallations;
    }

    /**
     * @return Instance[]
     *
     * @throws Exception
     */
    private function loadInstances()
    {
        $servers = $this->configurationService->getServers();

        $instances = [];
        foreach ($servers as $server) {
            $connection = $server->getConnection();
            $absolutePath = $server->getConnection()->absolutePath($server->getPath());

            foreach ($server->getEnvironments() as $environment) {
                foreach ($environment->getStages() as $stage) {
                    $instances[] = $this->createInstance($connection, $absolutePath, $server->getName(), $environment->getName(), $stage);
                }
            }
        }

        return $instances;
    }

    /**
     * @throws Exception
     */
    private function createInstance(Connection $connection, string $path, string $server, string $environment, string $stage): Instance
    {
        $instance = new Instance($connection, $path, $server, $environment, $stage);

        $installations = $this->installationService->loadInstallations($instance);
        if (count($installations) > 0) {
            $symlink = $instance->getCurrentSymlink();
            $symlinkExists = $instance->getConnection()->checkSymlinkExists($symlink);
            $currentFolder = $symlinkExists ? $instance->getConnection()->readSymlink($symlink) : null;
            foreach ($installations as $installation) {
                $instance->addInstallation($installation);
                if ($installation->getFolder() === $currentFolder) {
                    $instance->setCurrentInstallation($installation);
                }
            }
        }

        return $instance;
    }

    /**
     * @throws Exception
     */
    public function switchInstallation(Instance $instance, Installation $target): void
    {
        $currentSymlink = $instance->getCurrentSymlink();
        $connection = $instance->getConnection();

        // create new symlink
        $tempCurrentSymlink = $currentSymlink.'_';
        $connection->createSymlink($tempCurrentSymlink, $target->getFolder());

        // take old offline
        $old = $instance->getCurrentInstallation();
        if (null !== $old) {
            $this->installationService->isTakenOffline($instance, $old);
        }

        // switch
        $connection->replaceSymlink($tempCurrentSymlink, $currentSymlink);
        $instance->setCurrentInstallation($target);

        // take new online
        $this->installationService->wasTakenOnline($instance, $target);
    }

    /**
     * @throws Exception
     */
    public function removeOldInstallations(Deploy $deploy, Connection $connection)
    {
        $onlineNumber = $deploy->getTarget()->getCurrentInstallation()->getNumber();
        /** @var Installation[] $oldInstallations */
        $oldInstallations = [];
        foreach ($deploy->getTarget()->getInstallations() as $installation) {
            if ($installation->getNumber() < $onlineNumber) {
                $oldInstallations[$installation->getNumber()] = $installation;
            }
        }

        ksort($oldInstallations);

        // remove excess releases
        $installationsToDelete = count($oldInstallations) - $deploy->getTarget()->getKeepInstallations();
        foreach ($oldInstallations as $installation) {
            if ($installationsToDelete-- <= 0) {
                break;
            }

            $connection->removeFolder($installation->getFolder());
        }
    }

    /**
     * @throws Exception
     */
    public function getInstancesBySpecification(string $target)
    {
        $filter = Filter::createFromInstanceSpecification($target);

        return $this->getInstancesByFilter($filter);
    }
}
