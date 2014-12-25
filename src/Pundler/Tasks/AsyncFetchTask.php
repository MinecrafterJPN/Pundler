<?php

namespace Pundler\Tasks;

use pocketmine\scheduler\AsyncTask;
use pocketmine\Server;

class AsyncFetchTask extends AsyncTask
{
    const API_URL = 'http://forums.pocketmine.net/api.php';
    const RESOURCE_URL_BASE = 'http://forums.pocketmine.net/api.php?action=getResource&value=';

    public function onRun()
    {
        $repository = [];

        $json = json_decode(file_get_contents(self::API_URL), true)['resources'];
        foreach ($json as $value) {
            $pluginName = $value['title'];
            if (isset($repository[$pluginName]) && version_compare($repository[$pluginName]['version_id'], $value['version_id'], '>=')) continue;
            //get version id
            $repository[$pluginName]['version_id'] = $value['version_id'];
            // get prefix id
            $repository[$pluginName]['prefix_id'] = $value['prefix_id'];
            // get url
            $repository[$pluginName]['url'] = 'http://forums.pocketmine.net/index.php?plugins/' . $value['title'] . '.' .  $value['id'] . '/download&version=' . $value['version_id'];
            // get version string
            $resourceURL = $this->getResourceURL($value['id']);
            $repository[$pluginName]['version'] = json_decode(file_get_contents($resourceURL), true)['version_string'];
        }
        $this->setResult($repository);
    }

    public function onCompletion(Server $server)
    {
        $server->getPluginManager()->getPlugin('Pundler')->continueCurrentTask($this->getResult());
    }

    private function getResourceURL($id)
    {
        return self::RESOURCE_URL_BASE . $id;
    }
}