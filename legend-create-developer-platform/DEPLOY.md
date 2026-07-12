# Deploy — Legend Create Developer Platform v1.0.0

## Server details (from memory)
- WP path: `/home/utnifjdg/domains/legendcreate.com/public_html/gamingsite`
- Plugin path: `.../wp-content/plugins/legend-create-developer-platform`
- Table prefix: `wp2l_` (non-default)
- PHP: 8.1 | WP: 7.0

## Pre-deploy checklist
1. Back up the database via cPanel phpMyAdmin (export utnifjdg_wp121)
2. Verify WooCommerce is active — go to Plugins list

## Deploy via cPanel terminal (GitHub not yet set up for this plugin)

### Option A — Upload ZIP via cPanel File Manager
1. Upload `legend-create-developer-platform-v1.0.0.zip` to `wp-content/plugins/`
2. Extract in place
3. Activate via WP Admin → Plugins

### Option B — SCP/SFTP
```bash
scp dist/legend-create-developer-platform-v1.0.0.zip user@server:/home/utnifjdg/
ssh server
cd /home/utnifjdg
unzip legend-create-developer-platform-v1.0.0.zip -d /home/utnifjdg/domains/legendcreate.com/public_html/gamingsite/wp-content/plugins/
```

### Activation
```bash
wp --path="/home/utnifjdg/domains/legendcreate.com/public_html/gamingsite" plugin activate legend-create-developer-platform
wp --path="..." litespeed-purge all
```

## Post-activation steps

### 1. Create required pages
Run this WP-CLI command to create the platform pages:
```bash
wp --path="..." eval '
$pages = array(
  "developers"          => array("title"=>"Developer Services",   "shortcode"=>"[lcdp_developer_hero]\n[lcdp_pricing]\n[lcdp_memberships]\n[lcdp_addons]"),
  "playtest-crew"       => array("title"=>"Join the Playtest Crew","shortcode"=>"[lcdp_playtest_crew]\n[lcdp_tester_apply_form]"),
  "submit-game"         => array("title"=>"Submit Your Game",      "shortcode"=>"[lcdp_game_submit_form]"),
  "join-playtest-crew"  => array("title"=>"Apply to Playtest Crew","shortcode"=>"[lcdp_tester_apply_form]"),
  "developer-dashboard" => array("title"=>"Developer Dashboard",   "shortcode"=>"[lcdp_developer_dashboard]"),
  "tester-dashboard"    => array("title"=>"Tester Dashboard",      "shortcode"=>"[lcdp_tester_dashboard]"),
);
foreach($pages as $slug => $p) {
  if(!get_page_by_path($slug)) {
    wp_insert_post(array("post_type"=>"page","post_title"=>$p["title"],"post_name"=>$slug,"post_content"=>$p["shortcode"],"post_status"=>"publish"));
    echo "Created: $slug\n";
  }
}
'
```

### 2. Create WooCommerce products
The plugin auto-creates products on first activation. Confirm in WP Admin → Products (All → show hidden).

### 3. Add to nav menu
Add Developer Services (`/developers/`) and Playtest Crew (`/playtest-crew/`) to main navigation.

### 4. Purge cache
```bash
wp --path="..." litespeed-purge all
```

## Phase 2 tasks (next session)
- Expert Network pages (/experts/, /join-as-an-expert/)
- Targeted Playtest + Launch packages
- Automated matching suggestions
- Guide workflow integration with existing game_guide CPT
- Membership recurring billing (WooCommerce Subscriptions or Paid Memberships Pro)
- Sponsored feature workflow
- Analytics events

## Phase 3 tasks
- Developer lead discovery (approved sources only)
- AI lead scoring (human approval required)
- Human-approved outreach queue
- AI report drafting via OpenAI
- Scheduled follow-ups (max 2)
