<?php
defined('MOODLE_INTERNAL') || die();

$string['modulename']            = 'Awakelab Game';
$string['modulenameplural']      = 'Awakelab Games';
$string['modulename_help']       = 'Upload a self-contained HTML/CSS/JS game package (as a .zip file) and play it directly inside the course, embedded in the page.';
$string['pluginname']            = 'Awakelab Game';
$string['pluginadministration']  = 'Awakelab Game administration';
$string['awakegamename']         = 'Activity name';
$string['contentheader']         = 'Game package';
$string['packagefile']           = 'Game package (.zip)';
$string['packagefile_help']      = 'Upload a ZIP file with your game. It must contain an index.html file at the root of the ZIP, plus any CSS/JS/asset files it needs. When you upload a new ZIP it replaces the previous one.';
$string['noindexfile']           = 'No index.html file was found for this game. If you uploaded a ZIP, make sure index.html sits at the root (not inside a subfolder). If you generated it with AI, check that the prompt is not empty and that the API key is configured.';
$string['noinstances']           = 'There are no Awakelab Game activities in this course yet.';
$string['awakegame:addinstance'] = 'Add a new Awakelab Game activity';
$string['awakegame:view']        = 'Play an Awakelab Game activity';
$string['privacy:metadata']      = 'The Awakelab Game plugin does not store any personal data.';

$string['contentsource']         = 'Content source';
$string['contentsource_help']    = 'Choose whether to upload a .zip file with the game yourself, or describe the game you want and have it generated automatically with AI.';
$string['contentsource_upload']  = 'Upload a file (.zip)';
$string['contentsource_ai']      = 'Generate with AI (from a prompt)';
$string['aiprompt']              = 'Game description (prompt)';
$string['aiprompt_help']         = 'Describe the game you want in as much detail as possible: mechanics, goal, number of levels/questions, look and feel, etc. The AI will generate a playable HTML game from this description. If you don\'t say what topic/subject the game should be about (for example, you only describe "a quiz game" without saying about what), the AI will automatically use the name and description of the course section where you are creating the activity to infer the topic.';
$string['airegenerate']          = 'Regenerate from scratch on save';
$string['airegenerate_help']     = 'Enable this if you want to discard the current game (including any improvements applied to it) and generate it again from the original prompt. Use it, for example, if the plugin was updated and you want your game to pick up the new features from scratch. To request a specific change without losing what you already have, use the "Improvements" field below instead.';
$string['aiimprovement']         = 'Improvements / changes to the current game';
$string['aiimprovement_help']    = 'Describe a specific change or improvement you want applied to the game AS IT CURRENTLY IS (for example: "add a 60-second timer", "change the background colour to blue", "add 5 more questions about the solar system"). On save, the AI starts from the current game and applies only that change, without rebuilding it from the original prompt. After it is applied, this field is automatically cleared so you can request the next improvement later.';
$string['aipromptlocked']        = 'This is the original prompt used to create the game. It cannot be edited, to avoid the game being accidentally regenerated. To request changes, use the "Improvements" field below (or tick "Regenerate from scratch" if you want to start over with this same prompt).';
$string['aiheading']             = 'AI generation';
$string['aiheading_desc']        = 'Settings required so teachers can generate games by describing them in a prompt, using the Claude (Anthropic) API.';
$string['anthropicapikey']       = 'Anthropic (Claude) API key';
$string['anthropicapikey_desc']  = 'Anthropic API key used to generate games from a prompt. It is stored server-side only and never shown to users. You can create one at <a href="https://console.anthropic.com/" target="_blank">console.anthropic.com</a>. If left empty, "Generate with AI" will not work.';
$string['noapikey']              = 'The Anthropic API key has not been configured. An administrator must add it under Site administration → Plugins → Activity modules → Awakelab Game.';
$string['aigenerationfailed']    = 'Could not generate the game with AI: {$a}';
$string['aigenerationrefused']   = 'The AI declined to generate this game (the prompt may touch disallowed content). Try rephrasing the description.';
$string['aigenerationempty']     = 'The AI returned no content for the game. Try again or rephrase the prompt.';
$string['aitopicunverified']     = 'Could not automatically confirm that the generated game covers the expected topic ("{$a}"). Review the content and, if it is not correct, tick "Regenerate from scratch" and save again.';

$string['gradenotice']           = '<strong>Notice:</strong> the score is reported by the game itself, which runs as JavaScript in the student\'s browser. There is no server-side validation, so a technically-minded student could tamper with the reported score. Use this for practice/gamification, not as the sole source of a tamper-proof grade.';

$string['contentsource_library'] = 'Choose from the library';
$string['libraryentry']          = 'Library game';
$string['libraryentry_help']     = 'Choose a game already saved by you or another teacher at the school. It will be copied as-is as this activity\'s content (nothing new is generated with AI, so it\'s instant and has no cost).';
$string['librarychoose']         = 'Select a game...';
$string['libraryusedcount']      = 'used {$a} times';
$string['savetolibrary']         = 'Save this game to the library';
$string['savedtolibrary']        = 'Game saved to the library. It is now available to any teacher at the school.';
