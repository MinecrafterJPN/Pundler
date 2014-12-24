<?php

namespace Pundler\Tasks;

use pocketmine\scheduler\AsyncTask;
use pocketmine\Server;

class AsyncFetchTask extends AsyncTask
{
    public function onRun()
    {
        $apiURL = "http://forums.pocketmine.net/api.php";
        $repository = [];

        $json = json_decode(file_get_contents($apiURL), true)["resources"];
        foreach ($json as $value) {
            $pluginName = $value['title'];
            if (isset($repository[$pluginName]) && version_compare($repository[$pluginName]['version_id'], $value['version_id'], '>=')) continue;
            //get version id
            $repository[$pluginName]['version_id'] = $value['version_id'];
            // get url
            $repository[$pluginName]['url'] = 'http://forums.pocketmine.net/index.php?plugins/' . $value['title'] . '.' .  $value['id'] . '/download&version=' . $value['version_id'];
            // get version string
            $resourceURL = 'http://forums.pocketmine.net/api.php?action=getResource&value=' . $value['id'];
            $repository[$pluginName]['version'] = json_decode(file_get_contents($resourceURL), true)['version_string'];
        }
        $this->setResult($repository);
    }

    public function onCompletion(Server $server)
    {
        $server->getPluginManager()->getPlugin("Pundler")->continueCurrentTask($this->getResult());
    }
}