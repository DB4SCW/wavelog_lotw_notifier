# Wavelog LotW Notifier

This small php script looks at a defined station logbook inside your wavelog instance and does 2 things:

- (optional) If you marked specific QSOs to be watched for incoming LotW confirmations, send notifications once LotW arrives.
- (always) If a new DXCC gets confirmed by LotW, send notification.

You can configure the script to notify via Telegram Bot or Discord webhook (or both!).

At the moment, this is a personal project. But use it if you like.

ATTENTION! If you use the optional marking feature, this script will ALTER your wavelog database and add a new column.
It is named in a way that should NEVER interfere with any future wavelog development. But who knows... Be aware!
