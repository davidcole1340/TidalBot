<?php

namespace TidalBot;

use Discord\Discord;
use Discord\Helpers\Process;
use Discord\Parts\Channel\Channel;
use Discord\Parts\Channel\Message;
use Discord\Parts\User\Game;
use Discord\Voice\VoiceClient;
use Discord\WebSockets\Event;
use Discord\WebSockets\WebSocket;
use Evenement\EventEmitter;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Tidal\Tidal;

/** 
 * An instance of the TIDAL bot. Each channel is allocated an instance.
 */
class Instance extends EventEmitter
{
	/**
	 * The Discord client instance.
	 *
	 * @var Discord Client instance.
	 */
	protected $discord;

	/**
	 * The voice client.
	 *
	 * @var VoiceClient The voice client.
	 */
	protected $vc;

	/**
	 * The channel we are connected to.
	 *
	 * @var Channel The channel.
	 */
	protected $voiceChannel;

	/**
	 * The channel we handle commands with.
	 *
	 * @var Channel The channel.
	 */
	protected $textChannel;

	/**
	 * The TIDAL client instance.
	 *
	 * @var Tidal Client instance.
	 */
	protected $tidal;

	/**
	 * The instance session information.
	 *
	 * @var array Session information.
	 */
	protected $session = [];

	/**
	 * The Monolog logger.
	 *
	 * @var Logger The logger.
	 */
	protected $logger;

	/**
	 * Constructs an instance.
	 *
	 * @param Discord   $discord      The Discord instance.
	 * @param Channel   $voiceChannel The channel the instance is allocated to.
	 * @param Channel   $textChannel  The text channel that handles commands.
	 * @param Tidal     $tidal        The TIDAL client instance.
	 */
	public function __construct(Discord $discord, Channel $voiceChannel, Channel $textChannel, Tidal $tidal)
	{
		$this->discord = $discord;
		$this->voiceChannel = $voiceChannel;
		$this->textChannel = $textChannel;
		$this->tidal = $tidal;
		$this->logger = new Logger("Instance-{$this->voiceChannel->name}");
		$this->logger->pushHandler(new StreamHandler('php://stdout', Logger::INFO));

		$this->session['songQueue'] = new \SplQueue();

		$this->session['currentSongQuery'] = [];
		$this->session['currentSongQueryAuthor'] = null;

		$this->session['currentAlbumQuery'] = [];
		$this->session['currentAlbumQueryAuthor'] = null;

		$this->discord->joinVoiceChannel($voiceChannel, false, true, $this->logger)->then(function (VoiceClient $vc) {
			$this->vc = $vc;

			$this->logger->addInfo("Connected to voice channel.");
			$this->textChannel->sendMessage("Connected! I am ready for comamnds.");

			$this->discord->on(Event::MESSAGE_CREATE, [$this, 'handleMessage']);

			$tickQueue = function () use (&$tickQueue) {
				if ($this->session['songQueue']->count() < 1) {
					$this->discord->loop->addTimer(3, $tickQueue);

					return;
				}

				$track = $this->session['songQueue']->shift();
				$this->discord->updatePresence(
					$this->discord->factory(Game::class, [
						'name' => $track->title.' - '.$track->artists->first()->name,
					])
				);

				$track->getStreamURL()->then(function ($streamURL) use (&$tickQueue, $track) {
					$params = [
						'ffmpeg',
						'-i', $streamURL->url,
						'-f', 's16le',
						'-loglevel', 0,
						'-ar', 48000,
						'-ac', 2,
						'pipe:1',
					];

					$process = new Process(implode(' ', $params));
					$process->start($this->discord->loop);

					$this->vc->playRawStream($process->stdout)->then(function () use (&$tickQueue) {
						$this->logger->addInfo('Finished playing song.');

						$tickQueue();
					}, function ($e) {
						$this->logger->addInfo('Error playing track', [$e->getMessage()]);
						$this->textChannel->sendMessage("There was an error while playing the track: {$e->getMessage()}");
					});
				}, function ($e) {
					$this->logger->addInfo('Erorr getting the stream URL.', [$e->getMessage()]);
					$this->textChannel->sendMessage("There was an error trying to get the stream URL of the track: {$e->getMessage()}");
				});
			};

			$tickQueue();
		}, function ($e) {
			$this->textChannel->sendMessage("Oops! We ran into an issue while joining the voice channel: `{$e->getMessage()}`");
			$this->emit('error', [$e, $this]);
		});
	}

	/**
	 * Handles an incoming message.
	 * 
	 * @param  Message $message The text message.
	 * @param  Discord $discord The Discord instance.
	 * 
	 * @return void
	 */
	public function handleMessage($message, $discord)
	{
		if ($message->channel->id != $this->textChannel->id) {
			return;
		}

		$handlers = [
			/**
			 * Adds a song to the queue.
			 *
			 * @usage {prefix} song <song-name>
			 */
			'song'    => [$this, 'lookupSong'],

			/**
			 * Adds an album to the queue.
			 *
			 * @usage {prefix} album <album-name>
			 */
			'album'   => [$this, 'lookupAlbum'],

			/**
			 * Makes the bot leave the channel.
			 *
			 * @usage {prefix} leave
			 */
			'leave'   => [$this, 'leaveVoice'],

			/**
			 * Returns the current song queue.
			 *
			 * @usage {prefix} queue
			 */
			'queue'   => [$this, 'getQueue'],

			/**
			 * Pauses the current song.
			 *
			 * @usage {prefix} pause
			 */
			'pause'   => [$this->vc, 'pause'],

			/**
			 * Unpauses the current song.
			 *
			 * @usage {prefix} unpause
			 */
			'unpause' => [$this->vc, 'unpause'],

			/**
			 * Skips the current song.
			 *
			 * @usage {prefix} skip
			 * @alias next
			 */
			'skip'    => [$this->vc, 'stop'],
			'next'    => [$this->vc, 'stop'],

			/**
			 * Shows the help guide.
			 *
			 * @usage {prefix} help
			 */
			'help'    => function ($params, Message $message) use (&$handlers) {
				$reply = "**Commands:**\r\n\r\n";

				foreach ($handlers as $key => $handler) {
					$reply .= "`@{$this->discord->username} {$key}`\r\n";
				}

				$message->reply($reply);
			},
		];

		if (preg_match('/<@([0-9]+)> (.+)/', $message->content, $matches)) {
			array_shift($matches); // Remove the original message

			if (array_shift($matches) != $this->discord->id) {
				return; // not for us
			}

			$params = explode(' ', array_shift($matches));

			$this->logger->addInfo("Recieved command from {$message->author->username}", $params);

			$command = array_shift($params);

			if (isset($handlers[$command])) {
				call_user_func_array($handlers[$command], [$params, $message]);
			}
		}

		if ($message->author->id == $this->session['currentSongQueryAuthor']) {
			$castedInt = (int) $message->content;

			if ($castedInt == 0 || $castedInt > count($this->session['currentSongQuery'])) {
				$message->reply('That wasn\'t a valid answer. Queue will be reset.');

				$this->session['currentSongQuery'] = [];
				$this->session['currentSongQueryAuthor'] = null;

				return;
			}

			$track = $this->session['currentSongQuery'][$castedInt - 1];

			$this->session['songQueue']->push($track);
			$message->reply("Song {$track->title} has been added to the queue.");
			$this->logger->addInfo('Track added to queue.', [$track->title, $track->album->title, $track->artists[0]->name]);

			$this->session['currentSongQuery'] = [];
			$this->session['currentSongQueryAuthor'] = null;
		}

		if ($message->author->id == $this->session['currentAlbumQueryAuthor']) {
			$castedInt = (int) $message->content;

			if ($castedInt == 0 || $castedInt > count($this->session['currentAlbumQuery'])) {
				$message->reply('That wasn\'t a valid answer. Queue will be reset.');

				$this->session['currentAlbumQuery'] = [];
				$this->session['currentAlbumQueryAuthor'] = null;

				return;
			}

			$album = $this->session['currentAlbumQuery'][$castedInt - 1];

			$album->getTracks()->then(function ($tracks) use ($album, $message) {
				foreach ($tracks as $track) {
					$this->session['songQueue']->push($track);
				}

				$message->reply("Album {$album->title} has been added to the queue.");
				$this->logger->addInfo('Album added to queue.', [$album->title, $album->artists[0]->name]);

				$this->session['currentAlbumQuery'] = [];
				$this->session['currentAlbumQueryAuthor'] = null;
			});
		}
	}

	/**
	 * Handles song lookup commands.
	 *
	 * @param array   $params  Command paramaters.
	 * @param Message $message The original message.
	 *
	 * @return void 
	 */
	public function lookupSong($params, Message $message)
	{
		$searchString = implode(' ', $params);

		$searchParams = [
			'query' => $searchString,
			'types' => 'tracks',
			'limit' => 5,
		];

		$this->tidal->search($searchParams)->then(function ($responses) use ($searchString, $message) {
			$tracks = $responses['tracks'];
			$reply = '';

			$this->session['currentSongQuery'] = $tracks;
			$this->session['currentSongQueryAuthor'] = $message->author->id;

			$reply = "Completed search for **{$searchString}**:\r\n";

			foreach ($tracks as $index => $track) {
				++$index;
				$reply .= "**{$index}**: {$track->title} - {$track->album->title} - {$track->artists[0]->name}\r\n";
			}

			$reply .= "Please type the song number you have chosen in this text chat.";

			$message->channel->sendMessage($reply);
		}, function ($e) use ($message) {
			$message->reply("There was an issue while trying to run the search query: `{$e->getMessage()}`");
		});
	}

	/**
	 * Handles album lookup commands.
	 *
	 * @param array   $params  Command paramaters.
	 * @param Message $message The original message.
	 *
	 * @return void 
	 */
	public function lookupAlbum($params, Message $message)
	{
		$searchString = implode(' ', $params);

		$searchParams = [
			'query' => $searchString,
			'types' => 'albums',
			'limit' => 5,
		];

		$this->tidal->search($searchParams)->then(function ($responses) use ($searchString, $message) {
			$albums = $responses['albums'];
			$reply = '';

			$this->session['currentAlbumQuery'] = $albums;
			$this->session['currentAlbumQueryAuthor'] = $message->author->id;

			$reply = "Completed search for **{$searchString}**:\r\n";

			foreach ($albums as $index => $album) {
				++$index;
				$reply .= "**{$index}**: {$album->title} - {$album->artists[0]->name}\r\n";
			}

			$reply .= "Please type the album number you have chosen in this text chat.";

			$message->channel->sendMessage($reply);
		}, function ($e) use ($message) {
			$message->reply("There was an issue while trying to run the search query: `{$e->getMessage()}`");
		});
	}

	/**
	 * Handles album lookup commands.
	 *
	 * @param array   $params  Command paramaters.
	 * @param Message $message The original message.
	 *
	 * @return void 
	 */
	public function leaveVoice($params, Message $message)
	{
		$this->discord->removeListener(Event::MESSAGE_CREATE, [$this, 'handleMessage']);
		$this->vc->close();

		$this->textChannel->sendMessage('Bye!');
		$this->logger->addInfo('Voice instance closing...');
		$this->emit('closed', []);
	}

	/**
	 * Handles get queue commands.
	 *
	 * @param array   $params  Command paramaters.
	 * @param Message $message The original message.
	 *
	 * @return void 
	 */
	public function getQueue($params, Message $message)
	{
		$reply = "Here is the current queue:\r\n\r\n";

		foreach ($this->session['songQueue'] as $index => $track) {
			++$index;
			$reply .= "**{$index}**: {$track->title} - {$track->album->title} - {$track->artists[0]->name}\r\n";
		}

		$reply .= "\r\nTo remove a song, run '<@{$this->discord->id}> remove <id>'.";

		$message->reply($reply);
	}
}