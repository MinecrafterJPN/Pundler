<?php
/**
 * Pundler
 * @version 1.0.0
 * @author MinecrafterJPN
 */

namespace Pundler;

use pocketmine\plugin\PluginBase;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;

class Pundler extends PluginBase
{
    private $pundlePath, $lastFetch, $repository;

    public function onLoad()
    {
    }

    public function onEnable()
    {
        $this->saveDefaultConfig();
        $this->reloadConfig();

        $pundleFileName = "pundle.yml";
        $this->saveResource($pundleFileName);
        $this->pundlePath = $this->getDataFolder() . $pundleFileName;

        $this->lastFetch = 0;
        $this->repository = array();

        require_once("simple_html_dom.php");

        if ($this->getConfig()->get("auto_update")['at_startup']) {
            $this->getLogger()->info("Checking updates automatically...");
            $this->update($this->getConfig()->get("auto_update")['group'], true);
        }
    }

    public function onDisable()
    {
        if ($this->getConfig()->get("auto_update")['at_shutdown']) {
            $this->getLogger()->info("Checking updates automatically...");
            $this->update($this->getConfig()->get("auto_update")["group"], true);
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
                $option = strtolower($args[0]);
                switch ($option) {
                    case "fetch":
                        $this->fetchRepository(true);
                        break;

                    case "install":
                        $group = isset($args[1]) ? $args[1] : "default";
                        $this->install($group);
                        break;

                    case "update":
                        $group = isset($args[1]) ? $args[1] : "default";
                        $this->update($group);
                        break;

                    case "clean":
                        $group = isset($args[1]) ? $args[1] : "default";
                        $this->clean($group);
                        break;

                    case "all":
                        $group = isset($args[1]) ? $args[1] : "default";
                        $this->install($group);
                        $this->update($group);
                        $this->clean($group);
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

    private function fetchRepository($force = false)
    {
        if ($force === false and time() - $this->lastFetch <= $this->getConfig()->get("minimum_fetch_interval")) {
            $this->getLogger()->info("Skipped fetching repository...");
            return;
        }

        $this->getLogger()->info("Fetching repository...");

        $pageCount = 1;

        while (true) {
            $url = "http://forums.pocketmine.net/plugins/?page=" . $pageCount;
            if (get_headers($url)[0] !== "HTTP/1.1 200 OK") break;

            $html = \file_get_html($url);
            $pluginList = $html->find('ol.resourceList', 0)->find('li');
            foreach ($pluginList as $plugin) {
                $info = $plugin->find('div.main', 0)->find('.title', 0);
                $name = $info->find('a', 0)->class === "prefixLink" ? $info->find('a', 1) : $info->find('a', 0);
                $version = $info->find('.version', 0);
                $this->repository[$name->plaintext] = array("version" => $version->plaintext);
            }
            $html->clear();
            $pageCount++;
        }
        $json = json_decode(file_get_contents($this->getConfig()->get("forum_api_url")), true)["resources"];
        foreach ($json as $value) {
            $this->repository[$value['title']]["url"] = "http://forums.pocketmine.net/index.php?plugins/" . $value['title'] . "." . $value['id'] . "/download&version=" . $value['version_id'];
        }
        $this->lastFetch = time();
    }

    private function installPlugin($name)
    {
        $this->getLogger()->info("Installing $name (v" . $this->repository[$name]['version'] . ")...");
        //TODO: APIバージョンの確認 プラグインページにスクレイピングをかける...？
        $url = $this->repository[$name]['url'];
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        $file = curl_exec($ch);
        curl_close($ch);
        if (strstr($file, "__HALT_COMPILER();")) {
            $path = $this->getServer()->getPluginPath() . $name . ".phar";
            file_put_contents($path, $file);
            $dependList = $this->getServer()->getPluginManager()->loadPlugin($path)->getDescription()->getDepend();
            if (!empty($dependList)) {
                $this->getLogger()->info("Detected some dependencies");
                $this->getLogger()->info("Installing the dependencies...");
                foreach ($dependList as $depend) {
                    if (!isset($this->repository[$depend])) {
                        $this->getLogger()->error("\"$depend\" dose not exist!");
                    } else {
                        $this->installPlugin($depend);
                    }
                }
            }
            $this->getServer()->getPluginManager()->enablePlugin($this->getServer()->getPluginManager()->getPlugin($name));
            return true;
        } else {
            $this->getLogger()->error("\"$name\" is not phar file! Skipped installing");
            return false;
        }
    }

    private function install($group, $force = false)
    {
        $pundle = yaml_parse_file($this->pundlePath);
        if (!isset($pundle[$group])) {
            $this->getLogger()->error("Group \"$group\" dose not exist!");
            return;
        }
        $this->fetchRepository($force);
        $this->getLogger()->info("Target group: $group");

        $targetGroup = $pundle[$group];
        $numOfInstalledPlugins = 0;

        foreach ($targetGroup as $name => $info) {
            if ($name === "Pundler") continue;

            if ($this->getServer()->getPluginManager()->getPlugin($name) === null) {
                if (!isset($this->repository[$name])) {
                    $this->getLogger()->error("\"$name\" dose not exist in the repository!");
                    $this->getLogger()->error("Skipped installing");
                } else {
                    if ($this->installPlugin($name)) $numOfInstalledPlugins++;
                }
            }
        }
        $this->getLogger()->info("Successfully installed $numOfInstalledPlugins plugins");
    }

    private function update($group, $force = false)
    {
        $pundle = yaml_parse_file($this->pundlePath);
        if (!isset($pundle[$group])) {
            $this->getLogger()->error("Group \"$group\" dose not exist!");
            return;
        }
        $this->fetchRepository($force);
        $this->getLogger()->info("Target group: $group");

        $targetGroup = $pundle[$group];
        $numOfUpdatedPlugins = 0;

        foreach ($this->getServer()->getPluginManager()->getPlugins() as $name => $plugin) {
            if ($name === "Pundler") continue;

            if (isset($targetGroup[$name]) and isset($this->repository[$name])) {
                $currentVersion = $plugin->getDescription()->getVersion();
                $needUpdate = $this->analyzeVersionString($targetGroup[$name]["version"]);
                $latestVersion = $this->repository[$name]["version"];
                if ($needUpdate($currentVersion, $latestVersion)) {
                    $this->getServer()->getPluginManager()->disablePlugin($plugin);
                    unlink($this->getServer()->getPluginPath() . $name . ".phar");
                    if ($this->installPlugin($name)) $numOfUpdatedPlugins++;
                }
            }
        }
        $this->getLogger()->info("Successfully updated $numOfUpdatedPlugins plugins");
    }

    private function clean($group)
    {
        $pundle = yaml_parse_file($this->pundlePath);
        if (!isset($pundle[$group])) {
            $this->getLogger()->error("Group \"$group\" dose not exist!");
            return;
        }
        $this->getLogger()->info("Target group: $group");
        $targetGroup = $pundle[$group];
        $numOfCleanedPlugins = 0;

        foreach ($this->getServer()->getPluginManager()->getPlugins() as $name => $plugin) {
            if ($name === "Pundler") continue;

            $found = false;
            foreach ($targetGroup as $n => $info) {
                if ($name === $n) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $this->getLogger()->info("Uninstalling $name...");
                $this->getServer()->getPluginManager()->disablePlugin($plugin);
                if (unlink($this->getServer()->getPluginPath() . $name . ".phar")) $numOfCleanedPlugins++;
            }
        }
        $this->getLogger()->info("Successfully cleaned $numOfCleanedPlugins plugins");

    }

    private function analyzeVersionString($string)
    {
        $info = explode(" ", $string);
        if (is_numeric($info[0])) {
            $version1 = $info[0];
            if (isset($info[1]) and $info[1] === "~" and isset($info[2]) and is_numeric($info[2])) {
                $version2 = $info[2];
                return function($currentVersion, $latestVersion) use($version1, $version2) {
                    return ($version1 <= $latestVersion and $latestVersion <= $version2 and $currentVersion < $latestVersion);
                };

            } else {
                //TODO: 最新版が指定バージョンより新しい場合、Historyページを探して指定バージョンをインストールするようにする
                return function($currentVersion, $latestVersion) use($version1) {
                    return ($latestVersion === $version1) and ($currentVersion !== $version1);
                };
            }

        } else {
            if (isset($info[1])) {
                $condition = $info[0];
                $version = $info[1];
                switch ($condition) {
                    case ">":
                        return function($currentVersion, $latestVersion) use($version) {
                            return ($latestVersion > $version) and ($currentVersion < $latestVersion);
                        };

                    case ">=":
                        return function($currentVersion, $latestVersion) use($version) {
                            return ($latestVersion >= $version) and ($currentVersion < $latestVersion);
                        };

                    case "<":
                        return function($currentVersion, $latestVersion) use($version) {
                            return ($latestVersion < $version) and ($currentVersion < $latestVersion);
                        };

                    case "<=":
                        return function($currentVersion, $latestVersion) use($version) {
                            return ($latestVersion <= $version) and ($currentVersion < $latestVersion);
                        };

                    default:
                        return function($currentVersion, $latestVersion) {
                            return ($currentVersion < $latestVersion);
                        };
                }
            } else {
                return function($currentVersion, $latestVersion) {
                    return $currentVersion < $latestVersion;
                };
            }
        }
    }
}