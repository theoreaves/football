# Pass result signals and pending-penalty animation fix

Pending penalties deliberately omit the play-call form. The presentation loop
must treat an absent form as not visible, instead of reading null.hidden.
This prevents the loop from stopping before the flag announcement and decision.

The result inset now shows incomplete passes with a horizontal arm sweep and
completions with a raise-and-lower catch-confirmation gesture. Completed passes
that earn a first down or touchdown show the catch gesture for 2.4 seconds,
then the first-down/TD signal. Pending and nullified plays stay suppressed.
Sacks and interceptions do not show an offensive catch-confirmation gesture.

Gesture references:
- NFL rulebook official signals, #13 (incomplete pass / missed goal):
  https://operations.nfl.com/media/24emxacq/2024-nfl-rulebook.pdf
- San Diego County Football Officials Association, Complete Pass:
  https://www.sdcfoa.org/approved-signals
  This catch-confirmation mechanic is used for presentation; it is not labeled
  as a separate universal NFL scoring signal.
