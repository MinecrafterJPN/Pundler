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
use pocketmine\utils\TextFormat;

class Pundler extends PluginBase
{
    private $lastFetch, $repository;

    public function onLoad()
    {
    }

    public function onEnable()
    {
        $this->saveDefaultConfig();
        $this->reloadConfig();

        $this->lastFetch = 0;
        $this->repository = array();

        require_once("simple_html_dom.php");

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
                $option = strtolower($args[0]);
                switch ($option) {
                    case "fetch":
                        $this->fetchRepository(true);
                        break;

                    case "install":
                        if (!isset($args[1])) {
                            $this->getLogger()->error("/pundler install <pluginname>");
                            return true;
                        }
                        $name = $args[1];
                        $this->install($name);
                        break;

                    case "update":
                        $group = isset($args[1]) ? $args[1] : "default";
                        $this->update($group);
                        break;

                    case "doctor":
                        $this->doctor();
                        break;

                    case "search":
                        if (!isset($args[1])) {
                            $this->getLogger()->error("/pundler search <pluginname>");
                            return true;
                        }
                        $name = $args[1];
                        $this->search($name);
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
        if (time() - $this->lastFetch <= $this->getConfig()->get("minimum_fetch_interval")) {
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

    private function install($name)
    {
        if ($this->getServer()->getPluginManager()->getPlugin($name) !== null) {
            $this->getLogger()->error("\"$name\" is already installed");
            return;
        }

        $this->fetchRepository();

        if (!isset($this->repository[$name])) {
            $this->getLogger()->error("\"$name\" dose not exist in the repository!");
            return;
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
            $this->getLogger()->error("\"$name\" is not phar file!");
            $this->getLogger()->error("Failed to install");
            return false;
        }
    }

    private function update()
    {
        $this->fetchRepository();

        $numOfUpdated = 0;

        foreach ($this->getServer()->getPluginManager()->getPlugins() as $name => $plugin) {
            if ($name === "Pundler") continue;

            if (!isset($this->repository[$name])) {
                $this->getLogger()->error("\"$name\" dose not exist in the repository!");
                $this->getLogger()->error("Failed to update");
                continue;
            }

            $currentVersion = $plugin->getDescription()->getVersion();
            $latestVersion = $this->repository[$name]["version"];
            if ($currentVersion < $latestVersion) {
                $this->getLogger()->info("Updating \"$name\"...");
                $this->getServer()->getPluginManager()->disablePlugin($plugin);
                unlink($this->getServer()->getPluginPath() . $name . ".phar");
                $this->installPlugin($name);
                $numOfUpdated++;
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
            $solved++;
        }

        // Fixing config.yml
        if (isset($this->getConfig()->get("auto_update")['group'])) {
            $this->getLogger()->info("Fixing config.yml...");
            unset($this->getConfig()->get("auto_update")['group']);
            $this->getConfig()->save();
            $solved++;
        }

        $this->getLogger()->info("* Fixed $solved problems *");
    }

    private function search($name)
    {
        $this->getLogger()->info("Searching \"$name\"...");
        $found = 0;
        foreach (array_keys($this->repository) as $pluginname) {
            if (strpos($pluginname, $name) !== false) {
                $this->getLogger()->info($pluginname);
                $found++;
            }
        }
        $this->getLogger()->info("Found $found plugins");
    }
}