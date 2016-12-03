<?php declare(strict_types=1);

namespace Room11\Jeeves\Plugins;

use Amp\Promise;
use IntervalParser\IntervalParser;
use BrillTagger\BrillTagger;
use Room11\Jeeves\Chat\Client\Chars;
use Room11\Jeeves\Chat\Client\ChatClient;
use Room11\Jeeves\Chat\Client\PostFlags;
use Room11\Jeeves\Chat\Message\Command;
use Room11\Jeeves\Chat\Room\Room as ChatRoom;
use Room11\Jeeves\Storage\Admin as AdminStore;
use Room11\Jeeves\Storage\KeyValue as KeyValueStore;
use Room11\Jeeves\System\PluginCommandEndpoint;
use function Amp\cancel;
use function Amp\once;
use function Amp\resolve;

class Reminder extends BasePlugin
{
    private $chatClient;
    private $storage;
    private $watchers;
    private $admin;
    private $tagger;

    const USAGE = "Usage: `!!reminder [ examples | list | <text> [ at <time> | in <delay> ] | unset <id> ]` Try `!!reminder examples`" ;
    const REMINDER_REGEX = "/(.*)\s+(?:in|at)\s+(.*)/ui";
    const USERNAME_REGEX = "/^@(?<username>.*)/ui";
    const TIME_FORMAT_REGEX = "/(?<time>(?:\d|[01]\d|2[0-3]):[0-5]\d)[+-]?(?&time)?/ui";

    public function __construct(
        ChatClient $chatClient,
        KeyValueStore $storage,
        AdminStore $admin,
        BrillTagger $tagger,
        array $watchers = []
    ) {
        $this->chatClient = $chatClient;
        $this->watchers = $watchers;
        $this->storage = $storage;
        $this->tagger = $tagger;
        $this->admin = $admin;
    }

    private function nuke(Command $command) {
        return resolve(function() use($command) {
            if (!yield $this->admin->isAdmin($command->getRoom(), $command->getUserId())) {
                return $this->chatClient->postReply($command, "One cannot simply nuke the reminders without asking Dave.");
            }

            $reminders = yield $this->storage->getKeys($command->getRoom());
            if ($reminders) {
                foreach ($reminders as $key){
                    $key = (string) $key;
                    yield $this->storage->unset($key, $command->getRoom());
                }
            }
            return $this->chatClient->postMessage($command->getRoom(), "Reminders are gone.");
        });
    }

    private function unset(Command $command) {
        $messageId = (string) $command->getParameter(1);

        return resolve(function() use($command, $messageId) {
            if (!yield $this->admin->isAdmin($command->getRoom(), $command->getUserId())) {
                return $this->chatClient->postReply($command, "Only an admin can unset a reminder.");
            }

            if (yield $this->storage->exists($messageId, $command->getRoom())) {
                yield $this->storage->unset($messageId, $command->getRoom());
                return $this->chatClient->postMessage($command->getRoom(), "Reminder unset.");
            }

            return $this->chatClient->postReply($command, "I'm sorry, I couldn't find that key.");
        });
    }

    private function getAllReminders(Command $command): Promise
    {
        return resolve(function() use($command) {
            $message = "Registered reminders are:";

            $reminders = yield $this->storage->getAll($command->getRoom());
            if(!$reminders){
                return $this->chatClient->postMessage($command->getRoom(), "There aren't any scheduled reminders.");
            }

            $timeouts = [];
            foreach ($reminders as $key => $value) {
                $text = $value['text'];
                $user = $value['username'];
                $timestamp = $value['timestamp'];
                $seconds = $timestamp - time();

                if ($seconds <= 0) {
                    $timeouts[] = $key;
                    continue;
                }

                $message .= sprintf(
                    "\n%s %s %s %s %s %s - %s - %s ",
                    Chars::BULLET,
                    $text,
                    Chars::RIGHTWARDS_ARROW,
                    "Id: :" . $key,
                    Chars::RIGHTWARDS_ARROW,
                    date('l, dS F Y H:i (e)', $timestamp),
                    'Set by ' . $user,
                    'Seconds left: ' . $seconds
                );
            }

            if(count($timeouts) !== count($reminders)){
                return $this->chatClient->postMessage($command->getRoom(), $message);
            }
        });
    }

    private function setReminder(Command $command, string $commandName): Promise
    {
        return resolve(function() use($command, $commandName) {

            $intervalParser = new IntervalParser();

            switch ($commandName){
                case 'in':
                    $parameters = $intervalParser->normalizeTimeInterval(implode(" ", $command->getParameters()));
                    $expression = IntervalParser::$intervalSeparatorDefinitions . IntervalParser::$intervalWithTrailingData;

                    if(preg_match($expression, $parameters, $matches)){
                        $time = $matches['interval'] ?? false;
                        $text = $matches['trailing'] ?? false;
                    }
                    break;
                case 'at':
                    $time = $command->getParameter(0) ?? false; // 24hrs

                    if($time && preg_match(self::TIME_FORMAT_REGEX, $time)){
                        $text = implode(" ", array_diff($command->getParameters(), array($time)));
                    }
                    break;
                case 'reminder':
                    $parameters = implode(" ", $command->getParameters());

                    if(!preg_match(self::REMINDER_REGEX, $parameters, $matches)){
                        return $this->chatClient->postMessage($command->getRoom(), self::USAGE);
                    }

                    $time = $matches[2] ?? '';
                    $string = $matches[1] ?? '';
                    if(!$string || !$time) break;

                    $textOrUser = $command->getParameter(0);
                    $setBy = $command->getUserName();

                    # Find the target for message
                    $output  = $this->findTargetAndMessage($textOrUser, $string, $setBy);
                    $target  = $output['target'];
                    $message = $output['message'];

                    # Only an admin can set a reminder for someone else
                    if(!in_array($textOrUser, ['me', 'everyone', 'yourself']) && strtolower($setBy) !== strtolower($target)){
                        if (!yield $this->admin->isAdmin($command->getRoom(), $command->getUserId())) {
                            return $this->chatClient->postReply($command, "Only an admin can set a reminder for someone else.");
                        }
                    }

                    # Decide what to say and to whom
                    $message = $this->prepareReply($command, $target, $message);

                    # Assemble full string/sentence of reminder ping
                    $text = $this->buildReminderMessage($target, trim($message), $setBy);

                    # Normalize interval string
                    $time = $intervalParser->normalizeTimeInterval($time);
                    break;
            }

            $for = $textOrUser ?? '';

            if(empty($time)) return $this->chatClient->postMessage($command->getRoom(), "Have a look at the time again, yo!");
            if(empty($text)) return $this->chatClient->postMessage($command->getRoom(), self::USAGE);

            $timestamp = strtotime($time) ?: strtotime("+{$time}"); // false|int
            if (!$timestamp) return $this->chatClient->postMessage($command->getRoom(), "Have a look at the time again, yo!");

            $key = (string) $command->getId();
            $value = [
                'id' => $key,
                'for' => $for,
                'text' => $text,
                'delay' => $time,
                'userId' => $command->getUserId(),
                'username' => $command->getUserName(),
                'timestamp' => $timestamp
            ];

            $seconds = $timestamp - time();
            if ($seconds <= 0) return $this->chatClient->postReply($command, "I guess I'm late: " . $text);

            if(yield $this->storage->set($key, $value, $command->getRoom())){

                $watcher = once(function () use ($command, $value, $key) {
                    yield $this->storage->unset($key, $command->getRoom());

                    if(in_array($value['for'], ["everyone","yourself"])){
                        return $this->chatClient->postMessage($command->getRoom(), $value['text']);
                    } elseif ($value['for'] !== $value['username']){
                        return $this->chatClient->postMessage($command->getRoom(), $value['text'], PostFlags::ALLOW_PINGS);
                    }
                    # else reply to the setter
                    return $this->chatClient->postReply($command, $value['text']);
                }, $seconds * 1000);

                $this->watchers[] = $watcher;
                return $this->chatClient->postMessage($command->getRoom(), "Reminder set.");
            }

            return $this->chatClient->postMessage($command->getRoom(), "Dunno what happened but I couldn't set the reminder.");
        });
    }

    /**
     * Find the intended target of message
     *
     * @param string $textOrTarget  First parameter of message
     * @param string $message       Reminder text
     * @param string $setBy         $setBy
     * @return array
     */
    private function findTargetAndMessage(string $textOrTarget, string $message, string $setBy): array
    {
        $pings = [];
        $substring = false;

        switch ($textOrTarget){
            case 'me':
                $target = $setBy;
                break;
            case 'everyone':
                $target = "everyone";
                break;
            case 'yourself':
                $target  = 'myself';
                break;
            default:
                if(preg_match(self::USERNAME_REGEX, $textOrTarget, $matches)){
                    $pings[] = $matches['username'];
                }

                if($pings) {
                    $target = (count($matches) > 1) ? implode(", ", $pings) : $matches['username'];
                    break;
                }

                $target = $setBy;
                $substring = true;
                break;
        }

        return (!$substring)
            ? [ 'target' => $target, 'message' => substr($message, strlen($textOrTarget)) ]
            : [ 'target' => $target, 'message' => $message ];
    }

    /**
     * Check parameters for context and decide what to say and to whom.
     * !!remind @someone|me|yourself|everyone|you that|about|to foo in <time>
     *
     * @param Command $command
     * @param string  $target
     * @param string  $message
     * @return string
     */
    private function prepareReply(Command $command, string $target, string $message)
    {
        $username = $command->getUserName();

        # Tag parts of message before transforming for the target
        $partsOfMessage = $this->tagMessageParts($message);
        print_r($partsOfMessage);
        if (!$partsOfMessage) {
            return $this->chatClient->postReply($command, "Could not understand that message. NLP is hard, yo.");
        }

        # remind me [ to grab a beer | that Ace is waiting me | I am late ] in 2hrs
        foreach ($partsOfMessage as $key => $part){
            $username  = null; # getUserName()
            $tag       = $part['tag'];
            $token     = $part['token'];
            $prevToken = $partsOfMessage[$key - 1]['token'] ?? '';
            $nextToken = $partsOfMessage[$key + 1]['token'] ?? '';

            switch (strtolower($token)) {
                case 'to':
                case 'not': # [ to not do something | not to miss ]
                    if($prevToken != 'do' && $key < 3){

                        if (in_array($nextToken, ['to', 'not'])) {
                            $token .= '\s' . $nextToken;
                            $message = preg_replace("/{$token}/", "don't", $message, 1);
                            break;
                        }

                        $username = ($target == $command->getUserName()) ? null : $command->getUserName();

                        $message  = ($username == null || $target == 'everyone')
                            ? preg_replace("/{$token}/", "", $message, 1)
                            : $message;
                    }
                    break;
                case 'i':   # remind everyone I hate strtotime
                    $username = ($target == $command->getUserName()) ? null : $command->getUserName();

                    $message = ($username)
                        ? $this->translateVerbs($tag, $nextToken, $message)
                        : $this->translatePronouns($message, $username);
                    break;
                case 'that':
                    if($key == 0) $message = preg_replace("/{$token}/", '', $message, 1);

                    if(in_array($target, ['everyone', $command->getUserName()])){

                        $message = ($prevToken == 'about')
                            ? preg_replace("/about/", 'remember', $message, 1)
                            : preg_replace("/{$token}/", '', $message, 1);;

                    }

                    if($prevToken == 'about') $message = preg_replace("/{$token}/", '', $message, 1);
                    break;
                case 'yourself': # remind yourself that you are a bot, jeez.
                    $message = preg_replace("/{$token}/", '', $message, 1);
                    return "I don't need to be reminded " . $this->translatePronouns($message);
                case 'she':
                case 'he':
                    $username = null;
                    return $this->translatePronouns($message, $username, $command->getUserName());
                default:
                    $username = ($target == $command->getUserName()) ? null : $command->getUserName();
                    break;
            }
        }

        return $this->translatePronouns($message, $username) ?? $message;
    }

    /**
     * Assemble final sentence for reminder ping.
     *
     * @param string $target   Target found by findTargetAndMessage()
     * @param string $message  Message found by findTargetAndMessage()
     * @param string $setBy    $command->getUsername()
     * @return string
     */
    private function buildReminderMessage(string $target, string $message, string $setBy): string
    {
        $starters = [
            ' wanted me to remind you ',
            ' asked me to remind you '
        ];

        $grumbles = [
            ' So get on that, would ya?',
            " It's about time you get on that."
        ];

        # Check whether the sentence is terminated already
        if(!in_array(substr(trim($message), -1), [ '.', '!', '?' ])) $message .= '. ';

        if ($target == "everyone") {
            $message = 'o/ everyone, ' . $message;
        } elseif ($target == 'myself') {
            $message = ':-) ' . $message;
        } elseif ($setBy == $target) {
            if(random_int(0, 100) > 95) $message .= $grumbles[array_rand($grumbles)];
            $message = "@{$target}" . ', '. $message;
        } else {
            $body = ' earlier ' . $setBy . $starters[array_rand($starters)]. $message;
            $message = (substr_count($target, '@') < 1)
                ? "@{$target}" . ',' . $body
                : $target . $body;
        }

        return $message;
    }

    public function translateVerbs(string $tag, string $verb, string $message): string
    {
        switch (substr($tag, 0, 3)) {
            case 'HV': # have
                $p = "has";
                break;
            case 'HV*': # haven't
                $p = "hasn't";
                break;
            default:
                $p = $this->tagger->transformVerbsToThirdPerson($tag, $verb);
                break;
        }

        return preg_replace("/{$verb}/", $p, $message, 1);
    }

    # Translate pronouns and the form of to be, if found any.
    # $username = null means translatePronouns will translate sentence subject to 'you', otherwise to a 3rd person view
    public function translatePronouns(string $message, string $username = null, string $setBy = null): string
    {
        $expression = "/(?J)
            \b(?:
               (?<obj>i)(?:(?<part>'m)|\s(?<part>am|was)(?<neg>n't)?)?
               |(?<obj>you|they)(?:(?<part>'re)|\s(?<part>are|were)(?<neg>n't)?)?
               |(?<obj>it|he|she)(?:(?<part>'s)|\s(?<part>is|was)(?<neg>n't)?)?
               |(?<obj>(mine|me|yours?))
               |(?<obj>(my|your|it|him|her)(self)?)
            )\b
        /uix";

        $output = preg_replace_callback($expression,
            function ($matches) use($username, $setBy) {
                $matches = array_filter($matches);
                $object  = strtolower($matches['obj']);

                switch($object){
                    case 'i':
                        if($setBy){
                            $o = $setBy;
                            break;
                        }
                        $o = ($username) ?: 'you';
                        break;
                    case 'you':
                        $o = (isset($matches['part'])) ? 'I ' : 'me';
                        break;
                    case 'me':
                        $o = ($username) ?: 'you';
                        break;
                    case 'my':
                        $o = ($username) ? $username."'s " : 'your ';
                        break;
                    case 'yourself':  $o = 'myself';
                        break;
                    case 'myself':
                        $o = ($username) ? 'him/herself ' : 'yourself ';
                        break;
                    case 'mine':
                        $o = ($username) ? $username."'s " : 'yours ';
                        break;
                    case 'your':  $o = 'my ';
                        break;
                    case 'yours': $o = 'mine ';
                        break;
                    case 'he':
                    case 'she':
                        $o = (!$username) ? ' you ' : ' '.$object;
                        break;
                    case 'himself':
                    case 'herself':
                        $o = (!$username) ? ' yourself ' : ' '.$object;
                        break;
                    default: $o = $object;
                        break;
                }

                if(isset($matches['part'])){
                    switch($part = strtolower($matches['part'])){
                        case "'re":
                        case "are":
                            $o .= ($object != 'you') ? ' '.$part : 'am ';
                            break;
                        case 'am':
                        case "'m":
                            $o .= ($username) ? ' is ' : ' are';
                            break;
                        case 'was':
                            $o .= (in_array($object, ['she','he']) || $o == 'you') ? ' were' : ' '.$part;
                            break;
                        case 'were':
                            $o .= ($object != 'you') ? ' '.$part : ' was';
                            break;
                        case "'s":
                        case "is":
                            $o .= (in_array($object, ['he', 'she'])) ? ' are ' : ' is';
                            break;
                        default:
                            $o .= $part;
                            break;
                    }
                }

                if(isset($matches['neg'])) $o .= ' not ';

                return $o;
            }, $message
        );

        return $output ?? $message;
    }

    public function tagMessageParts(string $message): array
    {
        return $this->tagger->tag($message) ?? [];
    }

    public function apologizeForExpiredReminders(ChatRoom $room, array $reminders): \Generator
    {
        if(!$reminders) return;

        foreach ($reminders as $key) {
            $key = (string) $key;
            $value = yield $this->storage->get($key, $room);
            $text = $value['text'];
            $stamp = $value['timestamp'];
            $seconds = $stamp - time();

            if($seconds > 0) continue;

            $reply = "I guess I'm late but, " . $text;

            $this->watchers[] = once(function () use ($room, $key, $reply) {
                yield $this->storage->unset($key, $room);
                return $this->chatClient->postMessage($room, $reply, PostFlags::ALLOW_PINGS);
            }, 1000);
        }
    }

    public function rescheduleUpcomingReminders(ChatRoom $room, array $reminders): \Generator
    {
        if(!$reminders) return;

        $this->watchers = [];

        foreach ($reminders as $key){
            $key = (string) $key;
            $value = yield $this->storage->get($key, $room);
            $text  = $value['text'];
            $stamp = $value['timestamp'];
            $seconds = $stamp - time();

            if ($seconds <= 0) continue;

            $this->watchers[] = once(function () use ($room, $key, $text) {
                yield $this->storage->unset($key, $room);
                return $this->chatClient->postMessage($room, $text, PostFlags::ALLOW_PINGS);
            }, $seconds * 1000);
        }
    }

    /**
     * Handle a command message
     *
     * !!<reminder|remind> remind this <in|at> <time>
     * !!at <time> remind this
     * !!in <time> remind this
     *
     * @param Command $command
     * @return Promise
     */
    public function handleCommand(Command $command): Promise
    {
        return resolve(function() use($command) {

            if ($command->hasParameters() === false) {
                return $this->chatClient->postMessage($command->getRoom(), self::USAGE);
            }

            /* $command->getParameter(0) can be: list | examples | flush | unset | <text> | <time> */
            $textOrCommand = $command->getParameter(0);
            $commandName = $command->getCommandName(); // <reminder|in|at>

            switch ($commandName){
                case 'in':
                    return yield $this->setReminder($command, 'in');
                case 'at':
                    return yield $this->setReminder($command, 'at');
                case 'reminder':
                    break;
            }

            if (count(array_diff($command->getParameters(), array($textOrCommand))) < 1){
                switch ($textOrCommand){
                    case 'list':
                        return yield $this->getAllReminders($command);
                    case 'examples':
                        return yield $this->getExamples($command);
                    case 'nuke': // nukes all reminders
                        return yield $this->nuke($command);
                }
            }

            if( $command->getParameter(0) === 'unset'
                && $command->getParameter(1) !== null
                && count($command->getParameters()) <= 2
            ){ return yield $this->unset($command); }

            return yield $this->setReminder($command, 'reminder');
        });
    }

    public function enableForRoom(ChatRoom $room, bool $persist = true){
        $this->tagger = new BrillTagger();
        $reminders = yield $this->storage->getKeys($room);

        yield from $this->rescheduleUpcomingReminders($room, $reminders);
        yield from $this->apologizeForExpiredReminders($room, $reminders);
    }

    public function disableForRoom(ChatRoom $room, bool $persist = false){
        if(!$this->watchers) return;

        foreach ($this->watchers as $key => $id){
            cancel($id);
        }
    }

    public function getExamples(Command $command): Promise
    {
        $examples = "Examples: \n"
            . Chars::BULLET . " !!reminder foo at 18:00 \n"
            . Chars::BULLET . " With timezone: (ie. UTC-3) !!reminder foo at 18:00-3:00 \n"
            . Chars::BULLET . " !!at 22:00 Grab a beer! \n"
            . Chars::BULLET . " !!reminder do something in 2 hours \n"
            . Chars::BULLET . " !!remind me to grab a beer in 2 hours \n"
            . Chars::BULLET . " !!remind everyone that strpbrk is a thing... in 12 hours \n"
            . Chars::BULLET . " !!remind @anAdmin to unpin that last xkcd in 2 days\n"
            . Chars::BULLET . " !!remind yourself that you are a bot... in 5 secs\n"
            . Chars::BULLET . " !!in 2 days 42 hours 42 minutes 42 seconds 42! \n"
            . Chars::BULLET . " !!reminder unset 32901146 \n"
            . Chars::BULLET . " !!reminder list \n";

        return resolve(function () use($command, $examples) {
            return $this->chatClient->postMessage($command->getRoom(), $examples);
        });
    }

    public function getName(): string
    {
        return 'Reminders';
    }

    public function getDescription(): string
    {
        return 'Get reminded by an elephpant because, why not?';
    }

    public function getCommandEndpoints(): array
    {
        return [
            new PluginCommandEndpoint('reminder', [$this, 'handleCommand'], 'reminder'),
            new PluginCommandEndpoint('in', [$this, 'handleCommand'], 'in'),
            new PluginCommandEndpoint('at', [$this, 'handleCommand'], 'at')
        ];
    }

}

