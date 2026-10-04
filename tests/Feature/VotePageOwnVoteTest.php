<?php

use App\Models\Question;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Livewire\Volt\FragmentAlias;

// #52: the "New Ideas" cards never highlighted the viewer's own vote, because
// only "Top Suggestions" passed :user-votes. #48 passes the viewer's votes,
// re-read on every render, to both lists. These pin that.

/**
 * How many of the question's vote buttons in $direction render as primary
 * (accent), which is how a card marks the viewer's own vote. Same check as
 * Andras's reproduction in #52.
 */
function ownVoteButtons(string $html, Question $question, string $direction = 'upvote'): int
{
    return preg_match_all('/<button[^>]*bg-\[var\(--color-accent\)\][^>]*wire:click="'.$direction.'\('.$question->id.'\)"/', $html);
}

function castVote(Question $question, User $user, int $count): void
{
    DB::table('question_votes')->insert(['question_id' => $question->id, 'user_id' => $user->id, 'count' => $count]);
}

test('both lists highlight the viewer\'s own upvote (Andras\'s #52 reproduction)', function () {
    $viewer = User::factory()->create();
    $question = Question::factory()->for(User::factory())->create();
    castVote($question, $viewer, 1);

    $html = $this->actingAs($viewer)->get('/vote')->assertOk()->getContent();

    // One card in Top Suggestions and one in New Ideas.
    expect(ownVoteButtons($html, $question))->toBe(2)
        ->and(ownVoteButtons($html, $question, 'downvote'))->toBe(0);
});

test('both lists highlight the viewer\'s own downvote', function () {
    $viewer = User::factory()->create();
    $question = Question::factory()->for(User::factory())->create();
    castVote($question, $viewer, -1);

    $html = $this->actingAs($viewer)->get('/vote')->assertOk()->getContent();

    expect(ownVoteButtons($html, $question, 'downvote'))->toBe(2)
        ->and(ownVoteButtons($html, $question))->toBe(0);
});

test('someone else\'s vote highlights nothing for the viewer', function () {
    $viewer = User::factory()->create();
    $question = Question::factory()->for(User::factory())->create();
    castVote($question, User::factory()->create(), 1);

    $html = $this->actingAs($viewer)->get('/vote')->assertOk()->getContent();

    expect(ownVoteButtons($html, $question))->toBe(0);
});

test('a vote cast elsewhere is highlighted in both lists after the next refresh', function () {
    $viewer = User::factory()->create();
    $question = Question::factory()->for(User::factory())->create();
    $this->actingAs($viewer);
    $page = Livewire::test(FragmentAlias::encode('vote', resource_path('views/vote.blade.php')));
    expect(ownVoteButtons($page->html(), $question))->toBe(0);

    // From chat (!vote) or another tab, then a socket-driven or fallback refresh.
    $question->recordVote($viewer, 1);

    expect(ownVoteButtons($page->call('$refresh')->html(), $question))->toBe(2);
});
