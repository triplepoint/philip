<?php

/**
 * Philip
 *
 * PHP Version 5.3
 *
 * @package    philip
 * @copyright  2012, Bill Israel <bill.israel@gmail.com>
 */
namespace Philip;

use Pimple;
use Philip\EventListener;
use Philip\IRC\Event;
use Philip\IRC\Request;
use Philip\IRC\Response;
use Monolog\Logger;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\NullHandler;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * A Slim-inspired IRC bot.
 *
 * @package philip
 * @author Bill Israel <bill.israel@gmail.com>
 */
class Philip extends Pimple
{
    /** @var resource $socket The socket for communicating with the IRC server */
    private $socket;

    /** @var \Symfony\Component\EventDispatcher\EventDispatcher $dispatcher The event dispatcher */
    private $dispatcher;

    /** @var string $pidfile The location to write to, if write_pidfile is enabled */
    private $pidfile;

    /**
     * Constructor.
     *
     * @param array $config The configuration for the bot
     */
    public function __construct($config = array())
    {
        parent::__construct($config);
        $this->dispatcher = new EventDispatcher();
        $this->initialize();
    }

    /**
     * Destructor; ensure the socket gets closed.
     * Destroys pid file if set in config.
     */
    public function __destruct()
    {
        if (is_resource($this->socket)) {
            fclose($this->socket);
        }

        if (isset($this['write_pidfile']) && $this['write_pidfile']) {
            unlink($this->pidfile);
        }
    }

    /**
     * Adds an event handler to the list for when someone talks in a channel.
     *
     * @param string   $pattern  The RegEx to test the message against
     * @param callable $callback The callback to run if the pattern matches
     */
    public function onChannel($pattern, $callback)
    {
        $handler = new EventListener($pattern, $callback);
        $this->dispatcher->addListener('message.channel', array($handler, 'testAndExecute'));
    }

    /**
     * Adds an event handler to the list when private messages come in.
     *
     * @param string   $pattern  The RegEx to test the message against
     * @param callable $callback The callback to run if the pattern matches
     */
    public function onPrivateMessage($pattern, $callback)
    {
        $handler = new EventListener($pattern, $callback);
        $this->dispatcher->addListener('message.private', array($handler, 'testAndExecute'));
    }

    /**
     * Adds event handlers to the list for both channel messages and private messages.
     *
     * @param string   $pattern  The RegEx to test the message against
     * @param callable $callback The callback to run if the pattern matches
     */
    public function onMessages($pattern, $callback)
    {
        $handler = new EventListener($pattern, $callback);
        $this->dispatcher->addListener('message.channel', array($handler, 'testAndExecute'));
        $this->dispatcher->addListener('message.private', array($handler, 'testAndExecute'));
    }

    /**
     * Adds event handlers to the list for JOIN messages.
     *
     * @param callable $callback The callback to run if the pattern matches
     */
    public function onJoin($callback)
    {
        $handler = new EventListener(null, $callback);
        $this->dispatcher->addListener('server.join', array($handler, 'testAndExecute'));
    }

    /**
     * Adds event handlers to the list for PART messages.
     *
     * @param callable $callback The callback to run if the pattern matches
     */
    public function onPart($callback)
    {
        $handler = new EventListener(null, $callback);
        $this->dispatcher->addListener('server.part', array($handler, 'testAndExecute'));
    }

    /**
     * Adds event handlers to the list for ERROR messages.
     *
     * @param callable $callback The callback to run if the pattern matches
     */
    public function onError($callback)
    {
        $handler = new EventListener(null, $callback);
        $this->dispatcher->addListener('server.error', array($handler, 'testAndExecute'));
    }

    /**
     * Adds event handlers to the list for NOTICE messages.
     *
     * @param callable $callback The callback to run if the pattern matches
     */
    public function onNotice($callback)
    {
        $handler = new EventListener(null, $callback);
        $this->dispatcher->addListener('server.notice', array($handler, 'testAndExecute'));
    }

    /**
     * Returns the location of the pid file.
     *
     * @return mixed Read-only file resource, or null if there was an error opening the file
     */
    public function getPidfile()
    {
        $resource = false;

        if (isset($this->pidfile) && is_readable($this->pidfile)) {
            $resource = fopen($this->pidfile, 'r');
        }

        return $resource ? $resource : null;
    }

    /**
     * Loads a plugin. See the README for plugin documentation.
     *
     * @param string $classname The fully-qualified classname of the plugin to load
     *
     * @throws \InvalidArgumentException
     */
    public function loadPlugin($classname)
    {
        if (class_exists($classname) && $plugin = new $classname($this)) {
            if (!$plugin instanceof AbstractPlugin) {
                throw new \InvalidArgumentException('Class must be an instance of \Philip\AbstractPlugin');
            }

            $plugin->init();
        }
    }

    /**
     * Loads multiple plugins in a single call.
     *
     * @param array $classnames The fully-qualified classnames of the plugins to load.
     */
    public function loadPlugins($classnames)
    {
        foreach ($classnames as $classname) {
            $this->loadPlugin($classname);
        }
    }

    /**
     * Determines if the given user is an admin.
     *
     * @param string $user The username to test
     * @return boolean True if the user is an admin, false otherwise
     */
    public function isAdmin($user) {
        return in_array($user, $this['admins']);
    }

    /**
     * Starts the IRC bot.
     */
    public function run()
    {
        if ($this->connect()) {
            $this->login();
            $this->join();
            $this->listen();
        } else {
            die('Unable to connect to IRC server.' . PHP_EOL);
        }
    }

    /**
     * Connects to the IRC server.
     *
     * @return boolean True if the socket was created successfully
     */
    private function connect()
    {
        stream_set_blocking(STDIN, 0);
        $this->socket = fsockopen($this['hostname'], $this['port']);
        return (bool) $this->socket;
    }

    /**
     * Logs in to the IRC server with the user info in the config.
     */
    private function login()
    {
        $this->send(Response::nick($this['nick']));
        $this->send(Response::user(
            $this['nick'],
            $this['hostname'],
            $this['servername'],
            $this['realname']
        ));
    }

    /**
     * Joins the channels specified in the config.
     */
    private function join()
    {
        if (!is_array($this['channels'])) {
            $this['channels'] = array($this['channels']);
        }

        foreach ($this['channels'] as $channel) {
            $this->send(Response::join($channel));
        }
    }

    /**
     * Driver of the bot; listens for messages, responds to them accordingly.
     */
    private function listen()
    {
        do {
            $data = fgets($this->socket, 512);
            if (!empty($data)) {
                $request = $this->receive($data);
                $cmd = strtolower($request->getCommand());

                if ($cmd === 'privmsg') {
                    $event_name = 'message.' . ($request->isPrivateMessage() ? 'private' : 'channel');
                } else {
                    $event_name = 'server.' . $cmd;
                }

                // Skip processing if the incoming message is from the bot
                if ($request->getSendingUser() === $this['nick']) {
                    continue;
                }

                $event = new Event($request);
                $this->dispatcher->dispatch($event_name, $event);
                $responses = $event->getResponses();

                if (!empty($responses)) {
                    $this->send($responses);
                }
            }
        } while (!feof($this->socket));
    }

    /**
     * Convert the raw incoming IRC message into a Request object
     *
     * @param string $raw The unparsed incoming IRC message
     * @return Request The parsed message
     */
    private function receive($raw)
    {
        $this['logger']->debug('--> ' . $raw);
        return new Request($raw);
    }

    /**
     * Actually push data back into the socket (giggity).
     *
     * @param mixed $responses The response(s) to send back to the server
     */
    private function send($responses)
    {
        if (!is_array($responses)) {
            $responses = array($responses);
        }

        foreach ($responses as $response) {
            $response .= "\r\n";
            fwrite($this->socket, $response);
            $this['logger']->debug('<-- ' . $response);
        }
    }

    /**
     * Do some minor initialization work before construction is complete.
     */
    private function initialize()
    {
        $this->setupLogger();
        $this->writePidfile();
        $this->addDefaultHandlers();
    }

    /**
     * Sets up the logger, but only if debug is enabled.
     */
    private function setupLogger()
    {
        $this['logger'] = new Logger('philip');

        if (isset($this['debug']) && $this['debug'] == true) {
            $log_path = isset($this['log']) ? $this['log'] : false;

            if (!$log_path) {
                throw new \Exception("If debug is enabled, you must supply a log file location.");
            }

            try {
                $format = "[%datetime% - %level_name%]: %message%";
                $handler = new StreamHandler($log_path, Logger::DEBUG);
                $handler->setFormatter(new LineFormatter($format));
                $this['logger']->pushHandler($handler);
            } catch (\Exception $e) {
                die("Unable to open/read log file.");
            }
        } else {
            $this['logger']->pushHandler(new NullHandler());
        }
    }

    /**
     * If Philip is configured to write a pid file, open it, and write the pid into it.
     *
     * @throws \Exception If Philip is configured to write a pidfile, and
     *                    there's no 'pidfile' location in the configuration
     * @throws \Exception If Philip is unable to open the pidfile for writing
     */
    private function writePidfile()
    {
        if (isset($this['write_pidfile']) && $this['write_pidfile']) {
            if (!isset($this['pidfile'])) {
                throw new \Exception('Please supply a pidfile location.');
            }

            $this->pidfile = $this['pidfile'];

            if ($pidfile = fopen($this->pidfile, 'w')) {
                fwrite($pidfile, getmypid());
                fclose($pidfile);
            } else {
                die('Unable to open pidfile for writing.');
            }
        }
    }

    /**
     * Loads default event handlers for basic IRC commands.
     */
    private function addDefaultHandlers()
    {
        // When the server PINGs us, just respond with PONG and the server's host
        $pingHandler = new EventListener(null, function($event) {
            $event->addResponse(Response::pong($event->getRequest()->getMessage()));
        });

        // If an Error message is encountered, just log it for now.
        $log = $this['logger'];
        $errorHandler = new EventListener(null, function($event) use ($log) {
            $log->debug("ERROR: {$event->getRequest()->getMessage()}");
        });

        $this->dispatcher->addListener('server.ping', array($pingHandler, 'testAndExecute'));
        $this->dispatcher->addListener('server.error', array($errorHandler, 'testAndExecute'));
    }
}
