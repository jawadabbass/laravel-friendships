<?php

namespace Tests;

use Orchestra\Testbench\TestCase;

class FriendshipsGroupsTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();

        $this->loadLaravelMigrations(['--database' => 'testing']);
        $this->loadMigrationsFrom([
            '--database' => 'testing',
            '--path' => realpath(dirname(__DIR__).'/tests/database/migrations'),
        ]);
        $this->withFactories(realpath(dirname(__DIR__).'/database/factories'));
    }

    protected function getEnvironmentSetUp($app)
    {
        // Setup default database to use sqlite :memory:
        $app['config']->set('friendships.tables.fr_groups_pivot', 'user_friendship_groups');
        $app['config']->set('friendships.tables.fr_pivot', 'friendships');
        $app['config']->set('friendships.groups.acquaintances', 0);
        $app['config']->set('friendships.groups.close_friends', 1);
        $app['config']->set('friendships.groups.family', 2);
    }



    /** @test */
    public function user_can_add_a_friend_to_a_group()
    {

        $sender    = createUser();
        $recipient = createUser();

        $sender->befriend($recipient);
        $recipient->acceptFriendRequest($sender);

        $this->assertTrue((boolean)$recipient->groupFriend($sender, 'acquaintances'));
        $this->assertTrue((boolean)$sender->groupFriend($recipient, 'family'));

        // it only adds a friend to a group once
        $this->assertFalse((boolean)$sender->groupFriend($recipient, 'family'));

        // expect that users have been attached to specified groups
        $this->assertCount(1, $sender->getFriends(0, 'family'));
        $this->assertCount(1, $recipient->getFriends(0, 'acquaintances'));

        $this->assertEquals($recipient->id, $sender->getFriends(0, 'family')->first()->id);
        $this->assertEquals($sender->id, $recipient->getFriends(0, 'acquaintances')->first()->id);

    }

    /** @test */
    public function user_cannot_add_a_non_friend_to_a_group()
    {

        $sender   = createUser();
        $stranger = createUser();

        $this->assertFalse((boolean)$sender->groupFriend($stranger, 'family'));
        $this->assertCount(0, $sender->getFriends(0, 'family'));
    }

    /** @test */
    public function user_can_remove_a_friend_from_group()
    {

        $sender    = createUser();
        $recipient = createUser();

        $sender->befriend($recipient);
        $recipient->acceptFriendRequest($sender);

        $recipient->groupFriend($sender, 'acquaintances');
        $recipient->groupFriend($sender, 'family');

        $this->assertEquals(1, $recipient->ungroupFriend($sender, 'acquaintances'));

        $this->assertCount(0, $sender->getFriends(0, 'acquaintances'));

        // expect that friend has been removed from acquaintances but not family
        $this->assertCount(0, $recipient->getFriends(0, 'acquaintances'));
        $this->assertCount(1, $recipient->getFriends(0, 'family'));
    }

    /** @test */
    public function user_cannot_remove_a_non_existing_friend_from_group()
    {

        $sender     = createUser();
        $recipient  = createUser();
        $recipient2 = createUser();

        $sender->befriend($recipient);

        $this->assertEquals(0, $recipient->ungroupFriend($sender, 'acquaintances'));
        $this->assertEquals(0, $recipient2->ungroupFriend($sender, 'acquaintances'));
    }

    /** @test */
    public function user_can_remove_a_friend_from_all_groups()
    {

        $sender    = createUser();
        $recipient = createUser();

        $sender->befriend($recipient);
        $recipient->acceptFriendRequest($sender);

        $sender->groupFriend($recipient, 'family');
        $sender->groupFriend($recipient, 'acquaintances');

        $sender->ungroupFriend($recipient);

        $this->assertCount(0, $sender->getFriends(0, 'family'));
        $this->assertCount(0, $sender->getFriends(0, 'acquaintances'));
    }

    /** @test */
    public function it_returns_friends_of_a_group()
    {

        $sender     = createUser();
        $recipients = createUser([], 10);

        foreach ($recipients as $key => $recipient) {

            $sender->befriend($recipient);
            $recipient->acceptFriendRequest($sender);

            if ($key % 2 === 0) {
                $sender->groupFriend($recipient, 'family');
            }

        }

        $this->assertCount(5, $sender->getFriends(0, 'family'));
        $this->assertCount(10, $sender->getFriends());
    }


    /** @test */
    public function it_returns_all_user_friendships_by_group()
    {

        $sender     = createUser();
        $recipients = createUser([], 5);

        foreach ($recipients as $key=>$recipient) {

            $sender->befriend($recipient);

            if ($key < 4) {

                $recipient->acceptFriendRequest($sender);
                if ($key < 3) {
                    $sender->groupFriend($recipient, 'acquaintances');
                }
                else {
                    $sender->groupFriend($recipient, 'family');
                }

            }
            else {
                $recipient->denyFriendRequest($sender);
            }

        }

        //Assertions

        $this->assertCount(3, $sender->getAllFriendships('acquaintances'));
        $this->assertCount(1, $sender->getAllFriendships('family'));
        $this->assertCount(0, $sender->getAllFriendships('close_friends'));
        $this->assertCount(5, $sender->getAllFriendships('whatever'));
    }


    /** @test */
    public function it_returns_accepted_user_friendships_by_group()
    {

        $sender     = createUser();
        $recipients = createUser([], 4);

        foreach ($recipients as $recipient) {
            $sender->befriend($recipient);
        }

        $recipients[0]->acceptFriendRequest($sender);
        $recipients[1]->acceptFriendRequest($sender);
        $recipients[2]->denyFriendRequest($sender);

        $sender->groupFriend($recipients[0], 'family');
        $sender->groupFriend($recipients[1], 'family');

        $this->assertCount(2, $sender->getAcceptedFriendships('family'));

    }

    /** @test */
    public function it_returns_accepted_user_friendships_number_by_group()
    {

        $sender     = createUser();
        $recipients = createUser([], 20)->chunk(5);

        foreach ($recipients->shift() as $recipient) {
            $sender->befriend($recipient);
            $recipient->acceptFriendRequest($sender);
            $sender->groupFriend($recipient, 'acquaintances');
        }

        //Assertions

        $this->assertEquals(5, $sender->getFriendsCount('acquaintances'));
        $this->assertEquals(0, $sender->getFriendsCount('family'));
        $this->assertEquals(0, $recipient->getFriendsCount('acquaintances'));
        $this->assertEquals(0, $recipient->getFriendsCount('family'));
    }


    /** @test */
    public function it_returns_user_friends_by_group_per_page()
    {

        $sender     = createUser();
        $recipients = createUser([], 6);

        foreach ($recipients as $recipient) {
            $sender->befriend($recipient);
        }

        $recipients[0]->acceptFriendRequest($sender);
        $recipients[1]->acceptFriendRequest($sender);

        $recipients[2]->denyFriendRequest($sender);

        $recipients[3]->acceptFriendRequest($sender);
        $recipients[4]->acceptFriendRequest($sender);

        $sender->groupFriend($recipients[0], 'acquaintances');
        $sender->groupFriend($recipients[1], 'acquaintances');
        $sender->groupFriend($recipients[3], 'acquaintances');
        $sender->groupFriend($recipients[4], 'acquaintances');

        $sender->groupFriend($recipients[0], 'close_friends');
        $sender->groupFriend($recipients[3], 'close_friends');

        $sender->groupFriend($recipients[4], 'family');

        //Assertions

        $this->assertCount(2, $sender->getFriends(2, 'acquaintances'));
        $this->assertCount(4, $sender->getFriends(0, 'acquaintances'));
        $this->assertCount(4, $sender->getFriends(10, 'acquaintances'));

        $this->assertCount(2, $sender->getFriends(0, 'close_friends'));
        $this->assertCount(1, $sender->getFriends(1, 'close_friends'));

        $this->assertCount(1, $sender->getFriends(0, 'family'));

        $this->containsOnlyInstancesOf(\App\User::class, $sender->getFriends(0, 'acquaintances'));
    }


}
