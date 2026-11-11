// Local Arcade tuning only. These values never change stored player ratings.
export const GAMEPLAY_TUNING_VERSION = 'arcade-2';
export const DEFAULT_SPEED_SCALE = 0.925;
export const clamp = (value, low, high) => Math.max(low, Math.min(high, value));
export const finite = (value, fallback = 0) => Number.isFinite(Number(value)) ? Number(value) : fallback;
export const rating = (player, name) => clamp(finite(player?.ratings?.[name], 60), 0, 100);
export const normalizeSpeedScale = value => clamp(finite(value, DEFAULT_SPEED_SCALE), 0.75, 1.1);
export const actorSpeed = (player, scale = DEFAULT_SPEED_SCALE) => (5.5 + rating(player, 'speed') * 0.045) * scale;
// Keep acceleration responsive when lowering the shared offense/defense top speed.
export const actorAcceleration = player => 14 + rating(player, 'acceleration') * 0.16;
