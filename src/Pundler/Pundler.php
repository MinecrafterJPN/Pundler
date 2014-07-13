<?php
/**
 * Pundler
 * @version 1.3
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
    const OPERATION_SEARCH = 2;

    /** @var  array */
    private $repository;
    /** @var  int */
    private $lastFetchTime;
    /** @var  AsyncFetchTask */
    private $lastFetchTask;
    /** @var  int */
    private $currentOperation;
    /** @var  array */
    private $argsForOperation;

    public function onLoad()
    {
    }

    public function onEnable()
    {
        $this->saveDefaultConfig();
        $this->reloadConfig();

        $this->repository = array();
        $this->lastFetchTime = 0;
        $this->lastFetchTask = null;
        $this->currentOperation = self::OPERATION_NULL;
        $this->argsForOperation = array();

        if ($this->getConfig()->get("auto_update")['at_startup']) {
            $this->getLogger()->info("Checking updates automatically...");
            $this->prepareForUpdate();
        }
    }

    public function onDisable()
    {
        if ($this->getConfig()->get("auto_update")['at_shutdown']) {
            $this->getLogger()->info("Checking updates automatically...");
            $this->prepareForUpdate();
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
                        $this->prepareForUpdate();
                        break;

                    case "search":
                        if (!isset($args[1])) {
                            $this->getLogger()->error("/pundler search <keyword>");
                            return true;
                        }
                        $keyword = $args[1];
                        $this->prepareForSearch($keyword);
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
            $this->continueCurrentTask(false);
            return;
        }
        $this->getLogger()->info("Fetching repository...");

        $this->lastFetchTask = new AsyncFetchTask();
        $this->getServer()->getScheduler()->scheduleAsyncTask($this->lastFetchTask);
    }

    private function prepareForInstall($name)
    {
        if ($this->lastFetchTask instanceof AsyncFetchTask and !$this->lastFetchTask->isFinished()) {
            $this->getLogger()->error("Wait for the finish of a previous task");
            return;
        }
        if ($this->getServer()->getPluginManager()->getPlugin($name) !== null) {
            $this->getLogger()->error("\"$name\" is already installed");
        }

        $this->currentOperation = self::OPERATION_INSTALL;
        $this->argsForOperation = array($name);
        $this->fetchRepository();
    }

    private function prepareForUpdate()
    {
        if ($this->lastFetchTask instanceof AsyncFetchTask and !$this->lastFetchTask->isFinished()) {
            $this->getLogger()->error("Wait for the finish of a previous task");
            return;
        }
        $this->currentOperation = self::OPERATION_UPDATE;
        $this->fetchRepository();
    }

    private function prepareForSearch($keyword)
    {
        if ($this->lastFetchTask instanceof AsyncFetchTask and !$this->lastFetchTask->isFinished()) {
            $this->getLogger()->error("Wait for the finish of a previous task");
            return;
        }
        $this->currentOperation = self::OPERATION_SEARCH;
        $this->argsForOperation = array($keyword);
        $this->fetchRepository();
    }

    public function continueCurrentTask($repository)
    {
        if (is_array($repository)) {
            $this->lastFetchTime = time();
            $this->repository = $repository;
            $this->getLogger()->info("Finished fetching!");
        }
        switch ($this->currentOperation) {
            case self::OPERATION_NULL:
                break;
            case self::OPERATION_INSTALL:
                $name = array_shift($this->argsForOperation);
                if ($this->install($name)) $this->getLogger()->info("Successfully installed $name");
                break;
            case self::OPERATION_UPDATE:
                $this->update();
                break;
            case self::OPERATION_SEARCH:
                $keyword = array_shift($this->argsForOperation);
                $this->search($keyword);
                break;
        }
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
            $path = $this->getServer()->getPluginPath() . trim($name) . "_v" . trim($this->repository[$name]['version']) .".phar";
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
        if (($plugin = $this->getServer()->getPluginManager()->getPlugin($name)) === null) {
            $this->getLogger()->error("\"$name\" is not installed for your server");
            return;
        }
        $this->getServer()->getPluginManager()->disablePlugin($plugin);
        $removed = false;
        foreach (new \DirectoryIterator($this->getServer()->getPluginPath()) as $file) {
            $pattern = '/^'.$name.'.*\.phar/';
            if (preg_match($pattern, $file->getFileName())) {
                if (unlink($file->getPathname())) $removed = true;
                break;
            }
        }
        if ($removed) {
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
            if (!isset($this->repository[$name])) continue;
            $currentVersion = $plugin->getDescription()->getVersion();
            $latestVersion = $this->repository[$name]["version"];

            if (version_compare($currentVersion, $latestVersion) === -1) {
                $this->getLogger()->info("Updating \"$name\"...");
                $this->getServer()->getPluginManager()->disablePlugin($plugin);
                $this->remove($name);
                if ($this->install($name)) $numOfUpdated++;
                else $this->getLogger()->error("Failed to update");
            }
        }
        $this->getLogger()->info("Successfully updated $numOfUpdated plugins");
    }

    private function search($keyword)
    {
        $this->getLogger()->info("Searching \"$keyword\"...");
        $found = 0;
        foreach (array_keys($this->repository) as $pluginname) {
            if (stripos($pluginname, $keyword) !== false) {
                $this->getLogger()->info($pluginname);
                $found++;
            }
        }
        $this->getLogger()->info("Found $found plugins");
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
}