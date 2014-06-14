<?php

namespace DP\VoipServer\TeamspeakServerBundle\ServerQuery;

use DP\Core\CoreBundle\Socket\Exception\NotConnectedException;
use DP\Core\CoreBundle\Socket\Exception\SocketException;
use DP\VoipServer\TeamspeakServerBundle\Entity\TeamspeakServerInstance;
use DP\Core\CoreBundle\Exception\MaxServerException;
use DP\VoipServer\VoipServerBundle\Exception\OfflineServerException;
use DP\Core\CoreBundle\Exception\IPBannedException;

class QueryGateway
{
    /** @var TeamSpeak3_Adapter_ServerQuery */
    private $query;
    /** @var string $user */
    private $user;
    /** @var string $pass */
    private $pass;
    /** @var boolean $online */
    private $online;
    /** @var boolean $connected */
    private $connected = false;


    public function __construct($host, $port, $user, $pass, $timeout = 1)
    {
        $this->user = $user;
        $this->pass = $pass;

        try {
            $uri  = 'serverquery://' . $host . ':' . $port;
            $uri .= '?timeout=' . $timeout . '#no_query_clients';
            $this->query = \TeamSpeak3::factory($uri);

            $this->online = true;
        } catch (\TeamSpeak3_Transport_Exception $e) {
            $this->online = false;
        }
    }

    public function login()
    {
        if (!$this->online) {
            throw new OfflineServerException("You need to start the server before log in.");
        }

        try {
            $this->query->login($this->user, $this->pass);

            $this->connected = true;
        }
        catch (\TeamSpeak3_Adapter_ServerQuery_Exception $e) {
            $matches = [];
            $this->connected = false;

            if (preg_match('#^connection failed, you are banned \(you may retry in ([\d]*) seconds\)$#', $e->getMessage(), $matches) === 1) {

                throw new IPBannedException('Banned from the server for ' . $matches[1] . ' seconds.', $matches[1]);
            }
        }

        return $this->connected;
    }

    public function createInstance(TeamspeakServerInstance $instance)
    {
        $this->needConnected();

        $params = $this->getInstanceParams($instance);

        try {
            $details = $this->query->serverCreate($params);
        }
        catch (\TeamSpeak3_Adapter_ServerQuery_Exception $e) {
            if ($e->getMessage() == 'virtualserver limit reached') {
                throw new MaxServerException;
            }

            // TODO: Add exception for this case
            return false;
        }

        return $details;
    }

    public function deleteInstance($sid)
    {
        $this->needConnected();

        /** @var \TeamSpeak3_Node_Server $instance */
        $instance = $this->getInstance($sid);

        if ($instance->isOnline()) {
            $instance->stop();
        }

        $instance->delete();

        return true;
    }

    public function getInstanceList()
    {
        return $this->query->serverList();
    }

    public function startInstance($sid)
    {
        $this->needConnected();

        return $this->query->serverStart($sid);
    }

    public function stopInstance($sid)
    {
        $this->needConnected();

        return $this->query->serverStop($sid);
    }

    public function restartInstance($sid)
    {
        $this->needConnected();

        $this->stopInstance($sid);

        return $this->startInstance($sid);
    }

    public function isInstanceOnline($sid)
    {
        $this->needConnected();

        try {
            return $this->getInstance($sid)->isOnline();
        } catch (\TeamSpeak3_Adapter_ServerQuery_Exception $e) {}

        return false;
    }

    public function isInstanceOffline($sid)
    {
        try {
            return $this->getInstance($sid)->isOffline();
        } catch (\TeamSpeak3_Adapter_ServerQuery_Exception $e) {}

        return true;
    }

    public function getInstance($sid)
    {
        $this->needConnected();

        return $this->query->serverGetById($sid);
    }

    public function viewer($sid)
    {
        $viewer = new \TeamSpeak3_Viewer_Html();
        $instance = $this->getInstance($sid);

        return $instance->getViewer($viewer);
    }

    public function updateInstanceConfig(TeamspeakServerInstance $instance)
    {
        $sid    = $instance->getInstanceId();
        $params = $this->getInstanceParams($instance);

        if ($this->getInstance($sid)->modify($params)) {
            return $this->restartInstance($sid);
        }

        return false;
    }

    public function getInstanceParams(TeamspeakServerInstance $instance)
    {
        $params = [
            'virtualserver_name'       => $instance->getFullName(),
            'virtualserver_maxclients' => $instance->getMaxClients(),
            'virtualserver_autostart'  => intval($instance->isAutostart()),
            'virtualserver_port'       => $instance->getPort(),
        ];

        return array_merge($params, $this->getHostButtonParams($instance));
    }

    public function isOnline()
    {
        return $this->online;
    }

    public function isConnected()
    {
        return $this->connected;
    }

    private function needConnected()
    {
        if (!$this->connected && !$this->login()) {
            throw new NotConnectedException;
        }
    }

    private function getHostButtonParams(TeamspeakServerInstance $instance)
    {
        return [
            'virtualserver_hostbutton_tooltip' => 'DediPanel',
            'virtualserver_hostbutton_url'     => 'http://www.dedicated-panel.net',
            'virtualserver_hostbutton_gfx_url' => 'http://www.dedicated-panel.net/assets/img/icone/logo-min.png',
        ];
    }
}
