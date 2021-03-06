<?php declare(strict_types = 1);

namespace Room11\Jeeves\Tests\BuiltIn\Commands;

use Amp\Success;
use Room11\Jeeves\BuiltIn\Commands\Remove;
use Room11\Jeeves\Chat\Command;
use Room11\Jeeves\Storage\Admin as AdminStorage;
use Room11\Jeeves\System\BuiltInCommandInfo;
use Room11\StackChat\Client\ChatClient;
use Room11\StackChat\Client\PostedMessageTracker;
use Room11\StackChat\Entities\PostedMessage;
use Room11\StackChat\Room\AclDataAccessor;
use Room11\StackChat\Room\Room;

class RemoveTest extends AbstractCommandTest
{
    /** @var AdminStorage|\PHPUnit_Framework_MockObject_MockObject */
    private $admin;

    /** @var Remove|\PHPUnit_Framework_MockObject_MockObject */
    private $builtIn;

    /** @var ChatClient|\PHPUnit_Framework_MockObject_MockObject */
    private $client;

    /** @var AclDataAccessor|\PHPUnit_Framework_MockObject_MockObject */
    private $aclDataAccessor;

    /** @var Command|\PHPUnit_Framework_MockObject_MockObject */
    private $command;

    /** @var PostedMessageTracker|\PHPUnit_Framework_MockObject_MockObject */
    private $tracker;

    /** @var Room|\PHPUnit_Framework_MockObject_MockObject */
    private $room;

    public function setUp()
    {
        parent::setUp();

        $this->admin = $this->createMock(AdminStorage::class);
        $this->client = $this->createMock(ChatClient::class);
        $this->command = $this->createMock(Command::class);
        $this->aclDataAccessor = $this->createMock(AclDataAccessor::class);
        $this->room = $this->createMock(Room::class);
        $this->tracker = $this->createMock(PostedMessageTracker::class);

        $this->builtIn = new Remove(
            $this->client,
            $this->aclDataAccessor,
            $this->admin,
            $this->tracker
        );

        $this->setReturnValue($this->command, 'getRoom', $this->room);
    }

    public function testCommand()
    {
        $this->setReturnValue($this->admin, 'isAdmin', new Success(true));
        $this->setReturnValue($this->aclDataAccessor, 'isAuthenticatedUserRoomOwner', new Success(true));
        $this->setReturnValue($this->tracker, 'getCount', 1, 1);

        $this->command
            ->method('getParameter')
            ->with($this->equalTo(0))
            ->will($this->returnValue(1))
        ;

        $this->command->method('getId')->will($this->returnValue(113));

        $message = $this->createMock(PostedMessage::class);
        $command = $this->createMock(Command::class);

        $this->tracker
            ->expects($this->once())
            ->method('popMessage')
            ->will($this->returnValue($message))
        ;

        $message->method('getId')->will($this->returnValue(112));
        $message->method('getParentMessage')->will($this->returnValue($command));
        $command->method('getId')->will($this->returnValue(111));

        $this->client
            ->expects($this->once())
            ->method('moveMessages')
            ->with(
                $this->identicalTo($this->room),
                $this->isType('int'),
                $this->identicalTo(113),
                $this->identicalTo(112),
                $this->identicalTo(111)
            )
            ->will($this->returnValue(new Success(true)))
        ;

        \Amp\wait($this->builtIn->handleCommand($this->command));
    }

    public function testCommandInfo()
    {
        $this->assertInstanceOf(BuiltInCommandInfo::class, $this->builtIn->getCommandInfo()[0]);
    }

    public function testCommandWithoutAdmin()
    {
        $this->setReturnValue($this->admin, 'isAdmin', new Success(false));
        $this->expectReply("Sorry, you're not cool enough to do that :(");

        \Amp\wait($this->builtIn->handleCommand($this->command));
    }

    public function testCommandWithoutRoomOwner()
    {
        $this->setReturnValue($this->admin, 'isAdmin', new Success(true));
        $this->setReturnValue($this->aclDataAccessor, 'isAuthenticatedUserRoomOwner', new Success(false));
        $this->expectReply("Sorry, I'm not a room owner so I can't do that :(");

        \Amp\wait($this->builtIn->handleCommand($this->command));
    }

    public function testCommandWithEmptyTracker()
    {
        $this->setReturnValue($this->admin, 'isAdmin', new Success(true));
        $this->setReturnValue($this->aclDataAccessor, 'isAuthenticatedUserRoomOwner', new Success(true));
        $this->setReturnValue($this->tracker, 'getCount', 0, 1);
        $this->expectReply("I don't have any messages stored for this room, sorry");

        \Amp\wait($this->builtIn->handleCommand($this->command));
    }

    private function expectReply(string $reply)
    {
        $this->client
            ->expects($this->once())
            ->method('postReply')
            ->with(
                $this->identicalTo($this->command),
                $this->equalTo($reply)
            )
            ->will($this->returnValue(new Success(true)))
        ;
    }
}
