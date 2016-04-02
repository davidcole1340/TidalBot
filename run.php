<?php include __DIR__.'/vendor/autoload.php';

use Discord\Discord;
use Discord\Helpers\Process;
use Discord\WebSockets\WebSocket;
use React\EventLoop\Factory;
use Tidal\Tidal;

$config = json_decode(file_get_contents('config.json'), true);

if (is_null($config)) {
	echo "Could not parse the config.\r\n";

	die(1);
}

$loop    = Factory::create();
$discord = new Discord($config['token']);
$ws      = new WebSocket($discord, $loop);
$tidal   = new Tidal($loop);

$ws->on('ready', function ($discord) use ($ws, $tidal, $loop, $config) {
	echo "Discord WebSocket is ready.\r\n";

	$tidal->connect(
		$config['tidal']['username'],
		$config['tidal']['password']
	)->then(function ($tidal) use ($discord, $ws, $config, $loop) {
		echo "Connected to TIDAL.\r\n";

		$finalSongQueue = [];
		$jsonQueue = $config['tidal']['queue'];

		// Handle albums:
		foreach ($jsonQueue['albums'] as $albumQuery) {
			$tidal->search([
				'query' => $albumQuery,
				'types' => 'albums'
			])->then(function ($response) use (&$finalSongQueue) {
				$albums = $response['albums'];

				foreach ($albums as $album) {
					$album->getTracks()->then(function ($tracks) use (&$finalSongQueue) {
						foreach ($tracks as $track) {
							$track->getStreamUrl()->then(function ($streamUrl) use (&$finalSongQueue, $track) {
								echo "Added {$track->title}\r\n";
								$finalSongQueue[] = $streamUrl->url;
							}, function ($e) use ($track) {
								echo "Error getting the stream URL from: {$track->title} - {$e->getMessage()}\r\n";
							});
						}
					}, function ($e) use ($album) {
						echo "Error getting the tracks from: {$album->title} - {$e->getMessage()}\r\n";
					});
				}
			}, function ($e) use ($albumQuery) {
				echo "Error searching the TIDAL servers for: {$albumQuery} - {$e->getMessage()}\r\n";
			});
		}

		$guild = $discord->guilds->get('name', $config['voice']['guildName']);

		if (is_null($guild)) {
			echo "Error getting the guild.\r\n";
			die(1);
		}

		$channel = $guild->channels->getAll('type', 'voice')->get('name', $config['voice']['channelName']);

		if (is_null($channel)) {
			echo "Error getting the channel.\r\n";
			die(1);
		}

		$ws->joinVoiceChannel($channel)->then(function ($vc) use (&$finalSongQueue, $loop, $ws) {
			echo "Joined the voice channel.\r\n";

			$playSongFromQueue = function () use (&$playSongFromQueue, &$finalSongQueue, $loop, $ws, $vc) {
				if (count($finalSongQueue) < 1) {
					$loop->addTimer(3, $playSongFromQueue);

					return;
				}

				$url = array_shift($finalSongQueue);
				$process = new Process("ffmpeg -i {$url} -f s16le -loglevel 0 -ar 48000 -ac 2 pipe:1");
				$process->start($loop);

				$vc->playRawStream($process->stdout)->then(function () use (&$playSongFromQueue) {
					echo "Finished playing song.\r\n";

					$playSongFromQueue();
				}, function ($e) use (&$playSongFromQueue) {
					echo "Error playing song: {$e->getMessage()}\r\n";

					$playSongFromQueue();
				});
			};

			$playSongFromQueue();
		}, function ($e) {
			echo "Error joining the voice channel.\r\n";
			die(1);
		});
	}, function ($e) {
		echo "Error connecting to TIDAL: {$e->getMessage()}\r\n";
		die(1);
	});
});

$loop->run();
