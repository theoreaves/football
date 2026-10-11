                        <div class="min-w-0 rounded border border-gray-200 bg-gray-50 p-4">
                            <label for="{{ $field }}" class="block font-medium mb-2">{{ $label }}</label>
                            @if($team->{$field})<label class="block text-sm"><input type="checkbox" name="clear_{{ $field }}" value="1"> Remove this image</label>@endif

                            @if($mode === 'edit' && $team->{$field})
                                <div class="mb-2">
                                    <img
                                        src="{{ in_array($field, ['team_logo', 'helmet_logo_left', 'helmet_logo_right', 'midfield_logo', 'endzone_logo_left', 'endzone_logo_right']) ? route('teams.art', [$team, $field]) : Storage::disk('public')->url($team->{$field}) }}"
                                        alt="{{ $label }}"
                                        class="h-28 w-full object-contain border rounded bg-white p-2"
                                    />
                                    <div class="text-xs text-gray-600 mt-1 break-all">
                                        {{ $team->{$field} }}
                                    </div>
                                </div>
                            @endif

                            <input id="{{ $field }}" type="file" name="{{ $field }}" accept="image/*" class="block w-full min-w-0 text-sm text-gray-700 file:mr-3 file:rounded file:border-0 file:bg-blue-100 file:px-3 file:py-2 file:text-blue-800" />
                            <div class="text-xs text-gray-600 mt-1">
                                Uploading a new file replaces the existing one.
                            </div>
                        </div>
