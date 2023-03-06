## About

This profile is a support for Drupal training sessions. It works alone but can be completed with three submodules:

* `training_migrate_content` to create the training test content,
* `training_module`: for implementation examples,
* `training_correction`: for the correction of the exercises,
* `training_theme`: for the correction of the theming exercises,

## Get the tools

First, get the `training_profile` and put it in your Drupal profiles folder.

```
git clone https://github.com/makinacorpus/drupal-training-profile.git training_profile
```

Depending on the training to be given, you can complete this profile with the appropriate submodules.

```
git submodule init modules/training_migrate_content
git submodule init modules/training_module
git submodule init modules/training_correction
git submodule init themes/training_theme
git submodule update --recursive
```

## Install profile with Drush

```bash
vendor/bin/drush si -y training_profile \
  --locale=fr \
  --db-url=mysql://root@localhost/training \
  --account-mail=webmaster@training.local \
  --account-name=admin \
  --account-pass=admin \
  --site-mail=webmaster@training.local \
  --site-name="Training Drupal"

vendor/bin/drush locale:check && vendor/bin/drush locale:update
vendor/bin/drush cr
```

## Migrate content

```bash
vendor/bin/drush en -y training_migrate_content
bash web/profiles/training_profile/modules/training_migrate_content/script/migrate.sh
```
