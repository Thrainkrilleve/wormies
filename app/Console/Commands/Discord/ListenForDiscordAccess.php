<?php

declare(strict_types=1);

namespace App\Console\Commands\Discord;

use App\DiscordAccess\CreateDiscordAccessLink;
use App\DiscordAccess\DiscordAccessBot;
use App\DiscordAccess\DiscordAccessGateway;
use App\DiscordAccess\DiscordAccessSync;
use Discord\Discord;
use Discord\Parts\Guild\Guild;
use Discord\Parts\Interactions\ApplicationCommand;
use Discord\Parts\User\Member;
use Discord\WebSockets\Event;
use Discord\WebSockets\Intents;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

#[Signature('discord:access-listen')]
#[Description('Run the Discord access bot. It grants map access to linked members of a configured server.')]
final class ListenForDiscordAccess extends Command
{
    public function handle(DiscordAccessGateway $gateway, DiscordAccessBot $bot, DiscordAccessSync $sync): int
    {
        if (! $bot->configured()) {
            $this->components->error('DISCORD_ACCESS_BOT_TOKEN and DISCORD_ACCESS_APPLICATION_ID are required.');

            return self::FAILURE;
        }

        $token = config('services.discord.access_bot_token');
        $discord = new Discord([
            'token' => $token,
            'intents' => Intents::GUILDS | Intents::GUILD_MEMBERS,
        ]);
        $this->trap([SIGTERM, SIGQUIT], fn () => $discord->close());
        $restartSignal = Cache::get('discord.access.restart');
        $discord->getLoop()->addPeriodicTimer(5, function () use ($discord, $restartSignal): void {
            try {
                if (Cache::get('discord.access.restart') !== $restartSignal) {
                    $discord->close();
                }
            } catch (Throwable $exception) {
                report($exception);
            }
        });

        $discord->on('init', function (Discord $discord) use ($gateway, $bot, $sync): void {
            $guildIds = [];
            foreach ($discord->guilds as $guild) {
                $guildIds[] = (string) $guild->id;
            }
            $gateway->markReady($guildIds);
            $this->listenForLink($discord, $sync);
            try {
                $bot->registerCommands();
            } catch (Throwable $exception) {
                report($exception);
            }
            $this->components->info('Discord access bot is ready.');
        });

        $discord->on(Event::GUILD_CREATE, function (Guild $guild) use ($gateway): void {
            $gateway->onGuildCreate((string) $guild->id);
        });
        $discord->on(Event::GUILD_DELETE, function (Guild $guild) use ($gateway): void {
            $gateway->onGuildDelete((string) $guild->id);
        });
        $discord->on(Event::GUILD_MEMBER_ADD, function (Member $member) use ($gateway): void {
            $gateway->onMemberAdd((string) $member->guild_id, (string) $member->id);
        });
        $discord->on(Event::GUILD_MEMBER_REMOVE, function (Member $member) use ($gateway): void {
            $gateway->onMemberRemove((string) $member->guild_id, (string) $member->id);
        });

        $discord->run();

        return self::SUCCESS;
    }

    private function listenForLink(Discord $discord, DiscordAccessSync $sync): void
    {
        $links = app(CreateDiscordAccessLink::class);
        $discord->listenCommand('link', function (ApplicationCommand $interaction) use ($links): void {
            $user = $interaction->user;
            if ($user === null) {
                return;
            }

            $url = $links->url((string) $user->id, (string) $user->username);
            $interaction->respondWithMessage(
                \Discord\Builders\MessageBuilder::new()->setContent('Open this link while you are signed in. It expires in 10 minutes and works once: '.$url),
                true,
            )->then(null, fn (Throwable $exception) => report($exception));
        });
        $discord->listenCommand('unlink', function (ApplicationCommand $interaction) use ($sync): void {
            $user = $interaction->user;
            if ($user === null) {
                return;
            }

            $interaction->respondWithMessage(
                \Discord\Builders\MessageBuilder::new()->setContent($sync->unlinkInvokedUser((string) $user->id)),
                true,
            )->then(null, fn (Throwable $exception) => report($exception));
        });
    }
}
