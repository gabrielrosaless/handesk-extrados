<?php

namespace Tests\Feature;

use App\Authenticatable\Admin;
use App\Notifications\TicketAssigned;
use App\Notifications\TicketCreated;
use App\Requester;
use App\Ticket;
use App\User;
use Illuminate\Foundation\Testing\Concerns\InteractsWithExceptionHandling;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class SimpleTicketTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithExceptionHandling;

    public function setUp() : void{
        parent::setUp(); // TODO: Change the autogenerated stub
        Notification::fake();
    }

    private function validParams($overrides = []){
        return array_merge([
            "requester" => [
                "name"  => "johndoe",
                "email" => "john@doe.com"
            ],
            "title"         => "App is not working",
            "body"          => "I can't log in into the application",
            "tags"          => ["xef"]
        ], $overrides);
    }

    /** @test */
    public function can_create_a_ticket(){
        Notification::fake();
        $admin      = factory(Admin::class)->create();
        $nonAdmin   = factory(User::class)->create(["admin" => 0]);

        $response = $this->post('api/tickets',[
            "requester" => [
                "name"  => "johndoe",
                "email" => "john@doe.com"
            ],
            "title"         => "App is not working",
            "body"          => "I can't log in into the application",
            "tags"          => ["xef"]
        ],["token" => 'the-api-token']);

        $response->assertStatus( Response::HTTP_CREATED );
        $response->assertJson(["data" => ["id" => 1]]);

        tap( Ticket::first(), function($ticket) use($admin) {
            tap( Requester::first(), function($requester) use ($ticket){
                $this->assertEquals($requester->name, "johndoe");
                $this->assertEquals($requester->email, "john@doe.com");
                $this->assertEquals( $ticket->requester_id, $requester->id);
            });
            $this->assertEquals ( $ticket->title, "App is not working");
            $this->assertEquals ( $ticket->body, "I can't log in into the application");
            $this->assertTrue   ( $ticket->tags->pluck('name')->contains("xef") );
            $this->assertEquals( Ticket::STATUS_NEW, $ticket->status);

            Notification::assertSentTo(
                [$admin],
                TicketCreated::class,
                function ($notification, $channels) use ($ticket) {
                    return $notification->ticket->id === $ticket->id;
                }
            );
        });


        Notification::assertNotSentTo(
            [$nonAdmin], TicketCreated::class
        );
    }

    /** @test */
    public function can_create_a_ticket_with_js_injection(){
        Notification::fake();
        $admin      = factory(Admin::class)->create();
        $nonAdmin   = factory(User::class)->create(["admin" => 0]);

        $response = $this->post('api/tickets',[
            "requester" => [
                "name"  => "johndoe",
                "email" => "john@doe.com"
            ],
            "title"         => "App <script>is not working</script> >>>",
            "body"          => "I can't log in into the application<script>alert(1)</script>",
            "tags"          => ["xef"]
        ],["token" => 'the-api-token']);

        $response->assertStatus( Response::HTTP_CREATED );
        $response->assertJson(["data" => ["id" => 1]]);

        tap( Ticket::first(), function($ticket) use($admin) {
            tap( Requester::first(), function($requester) use ($ticket){
                $this->assertEquals($requester->name, "johndoe");
                $this->assertEquals($requester->email, "john@doe.com");
                $this->assertEquals( $ticket->requester_id, $requester->id);
            });
            $this->assertEquals ( $ticket->title, "App is not working >>>");
            $this->assertEquals ( $ticket->body, "I can't log in into the applicationalert(1)");
            $this->assertTrue   ( $ticket->tags->pluck('name')->contains("xef") );
            $this->assertEquals( Ticket::STATUS_NEW, $ticket->status);

            Notification::assertSentTo(
                [$admin],
                TicketCreated::class,
                function ($notification, $channels) use ($ticket) {
                    return $notification->ticket->id === $ticket->id;
                }
            );
        });


        Notification::assertNotSentTo(
            [$nonAdmin], TicketCreated::class
        );
    }

    /** @test */
    public function requester_is_required(){
        $response = $this->post('api/tickets',$this->validParams([
            "requester" => "",
        ]),["token" => 'the-api-token']);
        $response->assertStatus( Response::HTTP_UNPROCESSABLE_ENTITY );
        $response->assertJsonStructure([
            "error"
        ]);
        $this->assertEquals(0, Ticket::count() );
    }

    /** @test */
    public function title_is_required(){
        $response = $this->post('api/tickets',$this->validParams([
            "title" => "",
        ]),["token" => 'the-api-token']);
        $response->assertStatus( Response::HTTP_UNPROCESSABLE_ENTITY );
        $response->assertJsonStructure([
            "error"
        ]);
        $this->assertEquals(0, Ticket::count() );
    }

    /** @test */
    public function can_create_a_ticket_without_requester_email(){
        Notification::fake();

        $response = $this->post('api/tickets',[
            "requester" => [
                "name"  => "johndoe",
            ],
            "title"         => "App is not working",
            "body"          => "I can't log in into the application",
            "tags"          => ["xef"]
        ],["token" => 'the-api-token']);

        $response->assertStatus( Response::HTTP_CREATED );
        $response->assertJson(["data" => ["id" => 1]]);
    }

    /** @test */
    public function creating_a_ticket_of_a_requester_without_email_does_not_use_another_requester_without_email(){
        Notification::fake();

        factory(Requester::class)->create(["name" => "First requester", "email" => null]);
        $response = $this->post('api/tickets',[
            "requester" => [
                "name"  => "Second Requester",
            ],
            "title"         => "App is not working",
            "body"          => "I can't log in into the application",
            "tags"          => ["xef"]
        ],["token" => 'the-api-token']);

        $response->assertStatus( Response::HTTP_CREATED );
        $response->assertJson(["data" => ["id" => 1]]);
        tap(Ticket::first(), function($ticket){
            $this->assertEquals("Second Requester", $ticket->requester->name);
            $this->assertNull($ticket->requester->email);
        });
    }

    /** @test */
    public function requester_can_comment_the_ticket(){
        Notification::fake();
        $ticket = factory(Ticket::class)->create();
        $ticket->comments()->create(["body" => "first comment", "new_status" => 1]);

        $response = $this->post("api/tickets/{$ticket->id}/comments", [
            "requester" => [
                "name" => $ticket->requester->name,
                "email" => $ticket->requester->email
            ],
            "body" => "this is a comment"
        ],["token" => 'the-api-token']);

        $response->assertStatus ( Response::HTTP_CREATED );
        $response->assertJson   (["data" => ["id" => 2]]);

        $this->assertCount  (2, $ticket->comments);
        $this->assertEquals ($ticket->comments[1]->body, "this is a comment");

        //TODO: assert notifications
    }

    /** @test */
    public function requester_can_comment_the_ticket_with_js_injection(){
        Notification::fake();
        $ticket = factory(Ticket::class)->create();
        $ticket->comments()->create(["body" => "first comment", "new_status" => 1]);

        $response = $this->post("api/tickets/{$ticket->id}/comments", [
            "requester" => [
                "name" => $ticket->requester->name,
                "email" => $ticket->requester->email
            ],
            "body" => "<script> this is a comment </script>"
        ],["token" => 'the-api-token']);

        $response->assertStatus ( Response::HTTP_CREATED );
        $response->assertJson   (["data" => ["id" => 2]]);

        $this->assertCount  (2, $ticket->comments);
        $this->assertEquals ($ticket->comments[1]->body, " this is a comment ");

        //TODO: assert notifications
    }

    /** @test */
    public function commenting_a_closed_ticket_reopens_it(){
        Notification::fake();
        $ticket = factory(Ticket::class)->create(["status" => Ticket::STATUS_SOLVED]);

        $response = $this->post("api/tickets/{$ticket->id}/comments", [
            "requester" => [
                "name" => $ticket->requester->name,
                "email" => $ticket->requester->email
            ],
            "body" => "this is a comment"
        ],["token" => 'the-api-token']);

        $response->assertStatus ( Response::HTTP_CREATED );
        $response->assertJson   (["data" => ["id" => 1]]);

        $this->assertCount  (1, $ticket->comments);
        $this->assertEquals ($ticket->comments[0]->body, "this is a comment");
        $this->assertEquals(Ticket::STATUS_OPEN, $ticket->fresh()->status);
    }

    /** @test */
    public function can_assign_ticket_to_user(){
        Notification::fake();

        $user   = factory(User::class)->create();
        $ticket = factory(Ticket::class)->create();

        $this->assertNull( $ticket->user );
        $response = $this->post("api/tickets/{$ticket->id}/assign", ["user" => $user->id], ["token" => 'the-api-token']);

        $response->assertStatus ( Response::HTTP_CREATED );
        $this->assertEquals($ticket->fresh()->user->id, $user->id);

        Notification::assertSentTo(
            [$user],
            TicketAssigned::class,
            function ($notification, $channels) use ($ticket) {
                return $notification->ticket->id === $ticket->id;
            }
        );
    }

    /** @test */
    public function a_ticket_can_be_updated(){
        $ticket = factory(Ticket::class)->create();
        $this->assertEquals($ticket->status, Ticket::STATUS_NEW);

        $this->put("api/tickets/{$ticket->id}", ["status" => Ticket::STATUS_PENDING] ,["token" => 'the-api-token']);

        $this->assertEquals($ticket->fresh()->status, Ticket::STATUS_PENDING);
    }

    /** @test */
    public function a_ticket_created_by_the_same_requester_is_added_to_him(){
        $requester = factory(Requester::class)->create([
            "name"  => "Bruce Wayne",
            "email" => "bruce@wayne.com"
        ]);
        $this->assertCount(0, $requester->tickets );

        $response = $this->post('api/tickets',$this->validParams([
            "requester" => [
                "name"  => "Bruce Wayne",
                "email" => "bruce@wayne.com"
            ]
        ]),["token" => 'the-api-token']);

        $response->assertStatus ( Response::HTTP_CREATED );
        $this->assertEquals(1, Requester::count());
        $this->assertCount(1, $requester->fresh()->tickets);
    }
}
