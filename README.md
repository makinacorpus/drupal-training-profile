## About

This profile is a support for Drupal training sessions. It works alone but can be completed with three submodules:

* training_module: for implementation examples,
* training_correction: for the corrections of the exercises,

## Get the tools

First, get the `training_profile` and put it in your Drupal profiles folder.

```
git clone https://github.com/zewebmaster/Training-profile.git training_profile
```

Depending on the training to be given, you can complete this profile with the appropriate submodules.

##### Drupal dev

```
git submodule init modules/training_module
git submodule init modules/training_correction
git submodule update --remote
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
```