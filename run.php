<?php include __DIR__.'/vendor/autoload.php';

use Discord\Discord;
use Discord\Helpers\Collection;
use Discord\Helpers\Process;
use Discord\WebSockets\Event;
use Discord\WebSockets\WebSocket;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use React\EventLoop\Factory;
use TidalBot\Instance;
use Tidal\Tidal;

$config = json_decode(file_get_contents('config.json'), true);

if (is_null($config)) {
	echo "Could not parse the config.\r\n";

	die(1);
}

$logger    = new Logger('TidalBot');
$loop      = Factory::create();
$discord   = new Discord([
	'token' => $config['token'],
	'loop' => $loop,
	'logger' => $logger,
	'loadAllMembers' => true,
	'disabledEvents' => ['PRESENCE_UPDATE'],
]);
$tidal     = new Tidal($loop);
$instances = new Collection();

$logger->pushHandler(new StreamHandler('php://stdout', Logger::INFO));

$voiceClient = null;

$discord->on('ready', function ($discord) use (&$voiceClient, $tidal, $loop, $config, $logger, &$instances) {
	$logger->addInfo('Discord WebSocket is ready.');

	$tidal->connect(
		$config['tidal']['username'],
		$config['tidal']['password']
	)->then(function ($tidal) use (&$voiceClient, $discord, $config, $loop, $logger, &$instances) {
		$logger->addInfo('Connected to TIDAL.');

		$discord->on('message', function ($message) use($discord, $logger, &$instances, $tidal) {
			if ($message->author->id == $discord->id) {
				return;
			}

			if (preg_match('/<@([0-9]+)> (.+)/', $message->content, $matches)) {
				array_shift($matches); // Remove the original message

				if (array_shift($matches) != $discord->id) {
					return; // not for us
				}

				$params = explode(' ', array_shift($matches));
				$command = array_shift($params);

				if ($command == 'join') {
					foreach ($message->channel->guild->channels->getAll('type', 'voice') as $channel) {
						if (isset($channel->members[$message->author->id])) {
							$instance = new Instance($discord, $channel, $message->channel, $tidal);
							
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
				} elseif ($command == 'invite') {
					$message->reply($discord->application->getInviteURLAttribute(3148800));
				}
			}
		});
	}, function ($e) {
		echo "Error connecting to TIDAL: {$e->getMessage()}\r\n";
		die(1);
	});
});

$loop->run();
