<?php

namespace Pundler\Tasks;

use pocketmine\scheduler\AsyncTask;
use pocketmine\Server;

class AsyncFetchTask extends AsyncTask
{
    public function onRun()
    {
        $apiURL = "http://forums.pocketmine.net/api.php";
        $repository = array();
        require_once("simple_html_dom.php");

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
                if (isset($repository[$name->plaintext]) and version_compare($version->plaintext, $this->repository[$name->plaintext]["version"]) === -1 ) continue;
                $repository[$name->plaintext]["version"] = $version->plaintext;
            }
            $html->clear();
            $pageCount++;
        }
        $json = json_decode(file_get_contents($apiURL), true)["resources"];
        foreach ($json as $value) {
            if (isset($repository[$value['title']]["url"]) and $repository[$value['title']]['versionID'] >= $value['version_id']) continue;
            $repository[$value['title']]["versionID"] = $value['version_id'];
            $repository[$value['title']]["url"] = "http://forums.pocketmine.net/index.php?plugins/" . $value['title'] . "." . $value['id'] . "/download&version=" . $value['version_id'];
        }
        $this->setResult($repository);
    }

    public function onCompletion(Server $server)
    {
        $server->getPluginManager()->getPlugin("Pundler")->continueCurrentTask($this->getResult());
    }
}