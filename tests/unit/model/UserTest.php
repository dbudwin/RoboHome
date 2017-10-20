<?php

namespace Tests\Unit\Model;

use App\Device;
use App\User;

class UserTest extends ModelTestCase
{
    public function testAdd_GivenUserAddedToDatabase_DatabaseOnlyHasOneUserRecord(): void
    {
        $user = new User();
        $name = self::$faker->name();
        $email = self::$faker->email();
        $userId = self::$faker->uuid();

        $user = $user->add($name, $email, $userId);

        $this->assertCount(1, User::all());
        $this->assertEquals($name, $user->name);
        $this->assertEquals($email, $user->email);
        $this->assertEquals($userId, $user->user_id);
    }

    public function testDevices_GivenNoDevicesExist_ReturnsNoDevices(): void
    {
        $user = $this->createUser();

        $devices = $user->devices();

        $this->assertEquals(0, $devices->getResults()->count());
    }

    public function testDevices_GivenNoDevicesExist_ReturnsAllTheDevices(): void
    {
        $user = $this->createUser();

        $this->createDevice($user->id);
        $this->createDevice($user->id);
        $this->createDevice($user->id);

        $devices = $user->devices()->getResults();

        $this->assertEquals(3, $devices->count());
    }

    public function testDoesUserOwnDevice_GivenUserDoesNotOwnAnyDevices_ReturnsFalse(): void
    {
        $user = $this->createUser();

        $doesUserOwnDevice = $user->doesUserOwnDevice(self::$faker->randomNumber());

        $this->assertFalse($doesUserOwnDevice);
    }

    public function testDoesUserOwnDevice_GivenUserOwnsDevice_ReturnsTrue(): void
    {
        $user = $this->createUser();

        $device = new Device();
        $device = $device->add(self::$faker->word(), self::$faker->sentence(), $user->id, self::$faker->randomNumber());

        $doesUserOwnDevice = $user->doesUserOwnDevice($device->id);

        $this->assertTrue($doesUserOwnDevice);
    }

    private function createUser(): User
    {
        $user = new User();
        $name = self::$faker->name();
        $email = self::$faker->email();
        $userId = self::$faker->uuid();

        $user = $user->add($name, $email, $userId);

        return $user;
    }

    private function createDevice(string $userId): void
    {
        $device = new Device();
        $device->add(self::$faker->word(), self::$faker->sentence(), $userId, self::$faker->randomNumber());
    }
}
