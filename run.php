<?php include __DIR__.'/vendor/autoload.php';

use Discord\Discord;
use Discord\Helpers\Collection;
use Discord\Helpers\Process;
use Discord\WebSockets\Event;
use Discord\WebSockets\WebSocket;
use Monolog\Logger;
use React\EventLoop\Factory;
use TidalBot\Instance;
use Tidal\Tidal;

$config = json_decode(file_get_contents('config.json'), true);

if (is_null($config)) {
	echo "Could not parse the config.\r\n";

	die(1);
}

$loop      = Factory::create();
$discord   = new Discord($config['token']);
$ws        = new WebSocket($discord, $loop);
$tidal     = new Tidal($loop);
$logger    = new Logger('TidalBot');
$instances = new Collection();

$voiceClient = null;

$ws->on('ready', function ($discord) use (&$voiceClient, $ws, $tidal, $loop, $config, $logger, &$instances) {
	$logger->addInfo('Discord WebSocket is ready.');

	$tidal->connect(
		$config['tidal']['username'],
		$config['tidal']['password']
	)->then(function ($tidal) use (&$voiceClient, $discord, $ws, $config, $loop, $logger, &$instances) {
		$logger->addInfo('Connected to TIDAL.');

		$ws->on('message', function ($message) use ($ws, $discord, $logger, &$instances, $tidal) {
			if ($message->author->id == $discord->id) {
				return;
			}
			
			if (preg_match('/<@([0-9]+)> (.+)/', $message->content, $matches)) {
				array_shift($matches); // Remove the original message

				if (array_shift($matches) != $discord->id) {
					return; // not for us
				}

				$params = explode(' ', array_shift($matches));

				if (array_shift($params) == 'join') {
					foreach ($message->full_channel->guild->channels->getAll('type', 'voice') as $channel) {
						dump($channel->members);
						if (isset($channel->members[$message->author->id])) {
							$instance = new Instance($discord, $ws, $channel, $message->full_channel, $tidal);
							
							$removeInstance = function () use ($channel, &$instances) {
								unset($instances[$channel->id]);
							};

							$instance->on('closed', $removeInstance);
							$instance->on('error', $removeInstance);

							$instances[$channel->id] = $instance;

							return;
						}
					}

					$message->reply('We weren\'t able to find a voice channel with you inside it. Please join and try again.');
				}
			}
		});
	}, function ($e) {
		echo "Error connecting to TIDAL: {$e->getMessage()}\r\n";
		die(1);
	});
});

$loop->run();
