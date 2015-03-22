<?php

namespace Pundler;

use DirectoryIterator;
use pocketmine\plugin\PluginBase;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use Pundler\Tasks\AsyncFetchTask;

class Pundler extends PluginBase
{
    // operation constants
    const OPERATION_NULL = -1;
    const OPERATION_INSTALL = 0;
    const OPERATION_UPDATE = 1;
    const OPERATION_SEARCH = 2;

    // prefix constants
    const OUTDATED_PREFIX = 7;

    /** @var  array */
    private $repository = [];
    /** @var  int */
    private $lastFetchTime = 0;
    /** @var  AsyncFetchTask */
    private $lastFetchTask = null;
    /** @var  int */
    private $currentOperation = self::OPERATION_NULL;
    /** @var  array */
    private $argsForOperation = [];

    public function onLoad()
    {
    }

    public function onEnable()
    {
        $this->saveDefaultConfig();
        $this->reloadConfig();

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
                        if ($args[1] == '--url') {
                            if (isset($args[2])) {
                                $url = $args[2];
                                $this->directInstall($url);
                            } else {
                                $this->getLogger()->error("/pundler install --url <URL>");
                                return true;
                            }
                        } else {
                            $name = trim(implode(' ', array_slice($args, 1)));
                            $this->prepareForInstall($name);
                        }
                        break;

                    case "remove":
                        if (!isset($args[1])) {
                            $this->getLogger()->error("/pundler remove <pluginname>");
                            return true;
                        }
                        $name = trim(implode(' ', array_slice($args, 1)));
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
                        $keywords = array_slice($args, 1);
                        $this->prepareForSearch($keywords);
                        break;

                    case "doctor":
                        $this->doctor();
                        break;

                    case 'link':
                        if (!isset($args[1])) {
                            $this->getLogger()->error("/pundler link <plugin>");
                            return true;
                        }
                        $pluginname = $args[1];
                        if (!is_null($plugin = $this->getServer()->getPluginManager()->getPlugin($pluginname))) {
                            $this->getLogger()->error("'$plugin' has been already loaded");
                            return true;
                        }
                        foreach (new DirectoryIterator($this->getServer()->getPluginPath()) as $file) {
                            $pharPattern = '/^'.$pluginname.'.*\.phar/';
                            if (preg_match($pharPattern, $file->getFileName())) {
                                $this->getServer()->getPluginManager()->loadPlugin($file->getPath());
                            }
                        }
                        break;

                    case 'unlink':
                        if (!isset($args[1])) {
                            $this->getLogger()->error("/pundler unlink <plugin>");
                            return true;
                        }
                        $pluginname = $args[1];
                        if (is_null($plugin = $this->getServer()->getPluginManager()->getPlugin($pluginname))) {
                            $this->getLogger()->error("'$plugin' is not loaded");
                            return true;
                        }
                        $this->getLogger()->info("Unlinking $pluginname ...");
                        $this->getServer()->getPluginManager()->disablePlugin($plugin);
                        $this->getLogger()->info("Successfully unlinked $pluginname");
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
    
    private function directInstall($url)
    {
        $this->getLogger()->info("Downloading '$url' ...");
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function($ch, $header) use(&$filename) {
            $regex = '/Content-Disposition: attachment; filename="(.+?)"/i';
            if (preg_match($regex, $header, $matches)) {
                $filename = $matches[1];
            }
            return strlen($header);
        });
        $file = curl_exec($ch);
        curl_close($ch);

        if (strpos($file, "__HALT_COMPILER();")) {
            $path = $this->getServer()->getPluginPath() . $filename;

            $this->getLogger()->info($path);

            file_put_contents($path, $file);
            $plugin = $this->getServer()->getPluginManager()->loadPlugin($path);
            $dependList = $plugin->getDescription()->getDepend();
            $name = $plugin->getDescription()->getName();

            if (!empty($dependList)) {
                $this->getLogger()->info("Detected some dependencies");

                foreach ($dependList as $depend) {
                    $this->getLogger()->info("Manually install '$depend'");
                }
            }
            $this->getServer()->getPluginManager()->enablePlugin($this->getServer()->getPluginManager()->getPlugin($name));
        } else {
            $this->getLogger()->error("\"$filename\" is not phar file!");
            $this->getLogger()->error("Failed to install");
        }
    }

    private function prepareForInstall($name)
    {
        if ($this->lastFetchTask instanceof AsyncFetchTask and !$this->lastFetchTask->isFinished()) {
            $this->getLogger()->error("Wait for finishing a previous task");
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

    private function prepareForSearch($keywords)
    {
        if ($this->lastFetchTask instanceof AsyncFetchTask and !$this->lastFetchTask->isFinished()) {
            $this->getLogger()->error("Wait for the finish of a previous task");
            return;
        }
        $this->currentOperation = self::OPERATION_SEARCH;
        $this->argsForOperation = array($keywords);
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
                $keywords = array_shift($this->argsForOperation);
                $this->search($keywords);
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

        if (strpos($file, "__HALT_COMPILER();") && $this->repository[$name]['prefix_id'] !== self::OUTDATED_PREFIX) {
            $path = $this->getServer()->getPluginPath() . trim($name) . "_v" . trim($this->repository[$name]['version']) .".phar";
            file_put_contents($path, $file);
            $loadedPlugin = $this->getServer()->getPluginManager()->loadPlugin($path);
            $dependList = $loadedPlugin->getDescription()->getDepend();
            $exactName = $loadedPlugin->getDescription()->getName();
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
            $this->getServer()->getPluginManager()->enablePlugin($this->getServer()->getPluginManager()->getPlugin($exactName));
            return true;
        } else {
            $this->getLogger()->error("\"$name\" is outdated!");
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
        foreach (new DirectoryIterator($this->getServer()->getPluginPath()) as $file) {
            $pharPattern = '/^'.$name.'.*\.phar/';
            $directoryPattern = '/^'.$name.'$/';
            if (preg_match($pharPattern, $file->getFileName())) {
                if (unlink($file->getPathname())) $removed = true;
            }
            if (preg_match($directoryPattern, $file->getFileName()) and is_dir($file->getPathname())) {
                $this->removeDir($file->getPathname() .  DIRECTORY_SEPARATOR);
                rmdir($file->getPathname());
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

    private function search($keywords)
    {
        $keywordString = implode(' ', $keywords);
        $this->getLogger()->info("Searching \"$keywordString\"...");
        $found = 0;

        $this->getLogger()->info("* -------------------------- *");
        foreach (array_keys($this->repository) as $pluginname) {
            if ($this->containKeywords($pluginname, $keywords)) {
                $this->getLogger()->info($pluginname);
                $found++;
            }
        }
        $this->getLogger()->info("* -------------------------- *");
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

    private function removeDir($path)
    {
        $path .= "*";
        foreach(glob($path) as $file) @unlink($file);
    }

    private function containKeywords($target, $keywords)
    {
        foreach ($keywords as $keyword) {
            if (stripos($target, $keyword) === false) return false;
        }
        return true;
    }
}