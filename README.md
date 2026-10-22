```
 ______             _____  ______   __
|  ____|           |  __ \|  _ \ \ / /
| |__ _ __ ___  ___| |__) | |_) \ V /
|  __| '__/ _ \/ _ \  ___/|  _ < > <
| |  | | |  __/  __/ |    | |_) / . \
|_|  |_|  \___|\___|_|    |____/_/ \_\
Your Open Source Asterisk PBX GUI Solution
```
### What?
allowlist
This is a module for [FreePBX©](http://www.freepbx.org/ "FreePBX Home Page"). [FreePBX](http://www.freepbx.org/ "FreePBX Home Page") is an open source GUI (graphical user interface) that controls and manages [Asterisk©](http://www.asterisk.org/ "Asterisk Home Page") (PBX). FreePBX is licensed under GPL.
[FreePBX](http://www.freepbx.org/ "FreePBX Home Page") is a completely modular GUI for Asterisk written in PHP and Javascript. Meaning you can easily write any module you can think of and distribute it free of cost to your clients so that they can take advantage of beneficial features in [Asterisk](http://www.asterisk.org/ "Asterisk Home Page")

### Requirements and security notes
- FreePBX 17 (core 17.0.1 or later).
- **FreePBX framework 17.0.33 or later is recommended.** It sanitizes CR/LF in Asterisk Manager requests (FREEI-3044). This module already rejects numbers and descriptions containing control characters, so this is defense in depth; the module does not require it.
- Allowlist numbers are digits only, with an optional leading `+` (up to 20 digits). Caller IDs that are not in that form are never matched against the allowlist, and are not looked up in Contact Manager or the Asterisk phonebook either (a quote or space in a caller ID could otherwise reach an AGI database command), so they go to the non-allowlisted destination. Existing non-numeric entries (for example SIP usernames) no longer let a caller through and can be deleted.

### Caller ID is not identity (limits of an allowlist)
Screening matches the caller ID the trunk delivers. Caller ID can be spoofed, so anyone who presents an allowlisted number (for example a family member's mobile) passes. This is inherent to caller-ID allowlists. Numbers are matched exactly as stored: `+15555550100`, `15555550100` and `5555550100` are different entries, so store numbers in the form your trunk delivers. The module does not yet check STIR/SHAKEN attestation. Gating on A/B attestation is a possible future option; it needs a decision and trunk testing.

### Admin safeguards
- State-changing admin actions (add, edit, delete, block, pause, bulk delete, settings) need a per-session token from the module page. A forged cross-site request is rejected and changes nothing.
- Pausing screening (feature code or admin page) is limited to 24 hours and written to the log. An optional PIN can be required for the pause feature code.
- AGI logs show only the last 4 digits of caller and dialed numbers. To log full numbers while debugging, set the AstDB key `allowlist/debuglog` to `1`, and remove it afterwards.

### Setting up a FreePBX system
[See our WIKI](http://wiki.freepbx.org/display/FOP/Install+FreePBX)
### License
[This modules code is licensed as GPLv3+](http://www.gnu.org/licenses/gpl-3.0.txt)
### Contributing
To contribute code or modules back into the [FreePBX](http://www.freepbx.org/ "FreePBX Home Page") ecosystem you must fully read our Code License Agreement. We are not able to look at or accept patches or code of any kind until this document is filled out. Please take a look at [http://wiki.freepbx.org/display/DC/Code+License+Agreement](http://wiki.freepbx.org/display/DC/Code+License+Agreement) for more information
### Issues
Please file bug reports at http://issues.freepbx.org