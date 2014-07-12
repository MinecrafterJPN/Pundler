<?php

namespace Pundler\Tasks;

use pocketmine\scheduler\AsyncTask;
use pocketmine\Server;

class AsyncFetchTask extends AsyncTask
{
    private $repository, $api;

    public function __construct($api)
    {
        parent::__construct();
        $this->api = $api;
    }

    public function onRun()
    {
        $this->repository = array();
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
                //if (isset($this->repository[$name->plaintext]) and $version->plaintext <= $this->repository[$name->plaintext]["version"]) continue;
                $this->repository[$name->plaintext]["version"] = $version->plaintext;
            }
            $html->clear();
            $pageCount++;
        }
        $json = json_decode(file_get_contents($this->api), true)["resources"];
        foreach ($json as $value) {
            $this->repository[$value['title']]["url"] = "http://forums.pocketmine.net/index.php?plugins/" . $value['title'] . "." . $value['id'] . "/download&version=" . $value['version_id'];
        }

    }

    public function onCompletion(Server $server)
    {
        $server->getPluginManager()->getPlugin("Pundler")->continueCurrentTask($this->repository);
    }
}