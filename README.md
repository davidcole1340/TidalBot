## TidalBot

Plays music from TIDAL into a Discord channel.

### Installation

1. Install PHP and Composer
2. Install FFmpeg for encoding of audio
3. Clone this repository
4. Copy `config.default.json` to `config.json` and put in your details
5. Run `php run.php`

### Implementing with another bot

You are free to implement this package with your own bot. Require the package with Composer and then use the following:

```php
use TidalBot\Instance;
use Tidal\Tidal;

$discord = new Discord();
$ws      = new WebSocket($discord);

$ws->on('ready', function ($discord) use ($ws) {
	$textChannel = …; // Replace this with your own text channel.
	$voiceChannel = …; // Replace this with your own voice channel.

	$tidal = new Tidal();

	$tidal->connect(…)->then(function (Tidal $tidal) {
		$instance = new Instance(
			$discord,
			$ws,
			$voiceChannel,
			$textChannel,
			$tidal
		);
	});
});

$ws->run();
```

### To-Do

- Add shuffle modes