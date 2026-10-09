<h2 class="text-xl font-semibold mt-4">Injury history</h2>
<p class="my-3 text-gray-600">Season-game injuries only. Recovery statuses refer to the game in which the injury occurred, not future-week eligibility.</p>
@if(empty($injuryHistory))<p class="my-3">No recorded injuries for this player.</p>@else
<div class="overflow-x-auto"><table class="w-full text-left border-collapse"><thead><tr class="border-b"><th class="p-2">Season</th><th class="p-2">Week</th><th class="p-2">Team</th><th class="p-2">Injury</th><th class="p-2">Game status</th></tr></thead><tbody>
@foreach($injuryHistory as $injury)<tr class="border-b"><td class="p-2">{{ $injury['season'] }}</td><td class="p-2">{{ $injury['week'] }}</td><td class="p-2">{{ $injury['team'] }}</td><td class="p-2">{{ $injury['type'] }}</td><td class="p-2">{{ $injury['status'] }}</td></tr>@endforeach
</tbody></table></div>@endif
