<?php
/**
 * Pundler
 * @version 1.2
 * @author MinecrafterJPN
 */

namespace Pundler;

use pocketmine\plugin\PluginBase;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use Pundler\Tasks\AsyncFetchTask;

class Pundler extends PluginBase
{
    const OPERATION_NULL = -1;
    const OPERATION_INSTALL = 0;
    const OPERATION_UPDATE = 1;
    private $lastFetchTime, $repository, $currentOperation, $argsForOperation, $lastFetchTask;

    public function onLoad()
    {
    }

    public function onEnable()
    {
        $this->saveDefaultConfig();
        $this->reloadConfig();

        $this->lastFetchTime = 0;
        $this->repository = array();
        $this->currentOperation = self::OPERATION_NULL;
        $this->argsForOperation = array();
        $this->lastFetchTask = null;

        if ($this->getConfig()->get("auto_update")['at_startup']) {
            $this->getLogger()->info("Checking updates automatically...");
            $this->update();
        }
    }

    public function onDisable()
    {
        if ($this->getConfig()->get("auto_update")['at_shutdown']) {
            $this->getLogger()->info("Checking updates automatically...");
            $this->update();
        }
    }

    public function onCommand(CommandSender $sender, Command $command, $label, array $args)
    {
        if (strtolower($sender->getName()) !== "console") {
            $sender->sendMessage("Must be run on the console");
            return false;
        }
        switch ($command->getName()) {
            case "pundler":
                $option = isset($args[0]) ? strtolower($args[0]) : "";
                switch ($option) {
                    case "fetch":
                        $this->fetchRepository();
                        break;

                    case "install":
                        if (!isset($args[1])) {
                            $this->getLogger()->error("/pundler install <pluginname>");
                            return true;
                        }
                        $name = $args[1];
                        $this->prepareForInstall($name);
                        break;

                    case "remove":
                        if (!isset($args[1])) {
                            $this->getLogger()->error("/pundler remove <pluginname>");
                            return true;
                        }
                        $name = $args[1];
                        $this->remove($name);
                        break;


                    case "update":
                        $this->update();
                        break;

                    case "search":
                        if (!isset($args[1])) {
                            $this->getLogger()->error("/pundler search <pluginname>");
                            return true;
                        }
                        $name = $args[1];
                        $this->search($name);
                        break;

                    case "doctor":
                        $this->doctor();
                        break;

                    default:
                        return false;
                }
                break;

            default:
                return false;
        }
        return true;
    }

    private function fetchRepository()
    {
        if (time() - $this->lastFetchTime <= $this->getConfig()->get("minimum_fetch_interval")) {
            $this->getLogger()->info("Skipped fetching repository...");
            $this->continueCurrentTask($this->repository);
            return;
        }
        $this->getLogger()->info("Fetching repository...");

        $this->lastFetchTask = new AsyncFetchTask();
        $this->getServer()->getScheduler()->scheduleAsyncTask($this->lastFetchTask);

    }

    public function continueCurrentTask(array $repository)
    {
        $this->lastFetchTime = time();
        $this->repository = $repository;
        switch ($this->currentOperation) {
            case self::OPERATION_NULL:
                break;
            case self::OPERATION_INSTALL:
                $this->install(array_shift($this->argsForOperation));
                break;
            case self::OPERATION_UPDATE:
                $this->update();
                break;
        }
    }

    private function prepareForInstall($name)
    {
        if ($this->getServer()->getPluginManager()->getPlugin($name) !== null) {
            $this->getLogger()->error("\"$name\" is already installed");
            return false;
        }

        $this->currentOperation = self::OPERATION_INSTALL;
        $this->argsForOperation = array($name);
        $this->fetchRepository();
        return true;
    }

    private function install($name)
    {
        if (!isset($this->repository[$name])) {
            $this->getLogger()->error("\"$name\" dose not exist in the repository!");
            return false;
        }
        $this->getLogger()->info("Installing $name (" . $this->repository[$name]['version'] . ")...");

        $url = $this->repository[$name]['url'];
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        $file = curl_exec($ch);
        curl_close($ch);

        if (strpos($file, "__HALT_COMPILER();")) {
            $path = $this->getServer()->getPluginPath() . $name . "_v" . $this->repository[$name]['version'] .".phar";
            file_put_contents($path, $file);
            $dependList = $this->getServer()->getPluginManager()->loadPlugin($path)->getDescription()->getDepend();
            if (!empty($dependList)) {
                $this->getLogger()->info("Detected some dependencies");
                $this->getLogger()->info("Installing the dependencies...");
                foreach ($dependList as $depend) {
                    if (!isset($this->repository[$depend])) {
                        $this->getLogger()->error("\"$depend\" dose not exist!");
                    } else {
                        $this->install($depend);
                    }
                }
            }
            $this->getServer()->getPluginManager()->enablePlugin($this->getServer()->getPluginManager()->getPlugin($name));
            return true;
        } else {
            $this->getLogger()->error("\"$name\" is not phar file!");
            $this->getLogger()->error("Failed to install");
            return false;
        }
    }

    private function remove($name)
    {
        $this->getLogger()->info("Removing \"$name\"...");
        $plugin = $this->getServer()->getPluginManager()->getPlugin($name);
        if ($plugin === null) {
            $this->getLogger()->error("\"$name\" is not installed for your server");
            return;
        }
        $this->getServer()->getPluginManager()->disablePlugin($plugin);
        if (unlink($this->getServer()->getPluginPath() . $name . ".phar")) {
            $this->getLogger()->info("Successfully removed \"$name\"");
        } else {
            $this->getLogger()->error("Failed to remove \"$name\"");
            $this->getLogger()->error("After removing it manually, restart the server");
        }
    }

    private function update()
    {
        $numOfUpdated = 0;

        foreach ($this->getServer()->getPluginManager()->getPlugins() as $name => $plugin) {
            if ($name === "Pundler") continue;

            if (!isset($this->repository[$name])) {
                $this->getLogger()->error("\"$name\" dose not exist in the repository!");
                continue;
            }
            $currentVersion = $plugin->getDescription()->getVersion();
            $latestVersion = $this->repository[$name]["version"];
            if ($currentVersion < $latestVersion) {
                $this->getLogger()->info("Updating \"$name\"...");
                $this->getServer()->getPluginManager()->disablePlugin($plugin);
                $updated = false;
                foreach (new \DirectoryIterator($this->getServer()->getPluginPath()) as $file) {
                    $pattern = '/^'.$name.'.*\.phar/';
                    if (preg_match($pattern, $file->getFileName())) {
                        unlink($file->getPathname());
                        if ($this->install($name)) {
                            $numOfUpdated++;
                            $updated = true;
                        }
                        break;
                    }
                }
                if (!$updated) {
                    $this->getLogger()->error("Failed to update");
                }
            }
        }
        $this->getLogger()->info("Successfully updated $numOfUpdated plugins");
    }

    private function doctor()
    {
        $this->getLogger()->info("* Started doctor operation *");
        $solved = 0;
        // Removing pundle.yml
        if (file_exists($this->getDataFolder()."pundle.yml")) {
            $this->getLogger()->info("Removing pundle.yml...");
            unlink($this->getDataFolder()."pundle.yml");
            $solved++;
        }

        // Fixing config.yml
        $autoUpdate = $this->getConfig()->get("auto_update");
        if (isset($autoUpdate['group'])) {
            $this->getLogger()->info("Fixing config.yml...");
            $contents = file_get_contents($this->getDataFolder()."config.yml");
            $fixedContents = str_replace("# group: (string) target group", "", $contents);
            $fixedContents = str_replace("group: default", "", $fixedContents);
            file_put_contents($this->getDataFolder()."config.yml", $fixedContents);
            $solved++;
        }

        $this->getLogger()->info("* Fixed $solved problems *");
    }

    private function search($name)
    {
        $this->fetchRepository();
        $this->getLogger()->info("Searching \"$name\"...");
        $found = 0;
        foreach (array_keys($this->repository) as $pluginname) {
            if (stripos($pluginname, $name) !== false) {
                $this->getLogger()->info($pluginname);
                $found++;
            }
        }
        $this->getLogger()->info("Found $found plugins");
    }
}