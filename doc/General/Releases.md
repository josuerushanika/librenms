# Choosing a release

We try to ensure that breaking changes aren't introduced by utilising
various automated code, syntax and unit testing along with manual code
review. However bugs can and do get introduced as well as major
refactoring to improve the quality of the code base.

We have two branches available for you to use. The default is the `master` branch.

## Development branch

Our `master` branch is our dev branch, this is actively commited to
and it's not uncommon for multiple commits to be merged in daily. As
such sometimes changes will be introduced which will cause unintended
issues. If this happens we are usually quick to fix or revert those changes.

We appreciate everyone that runs this branch as you are in essence
secondary testers to the automation and manually testing that is done
during the merge stages.

You can configure your install (this is the default) to use this
branch by setting:

!!! setting "system/updates"
    ```bash
    lnms config:set update_channel master
    ```

Then ensure you are on the master branch by doing:

```bast
cd /opt/librenms
git checkout master
./daily.sh
```

## Stable branch

With this in mind, we provide a monthly stable release which is
released on or around the middle of the month, usually on a weekday.
Merging of pull requests (aside from Bug fixes) are typically stopped
days leading up to the release to ensure that we have a clean working
branch at that point.

The [changelog](Changelog.md) will be updated and will reference the
release number and date so you can see what changes have been made since
the last release.

To switch to using stable branches you can set:

!!! setting "system/updates"
    ```bash
    lnms config:set update_channel release
    ```

This will pause updates until the next stable release, at that time LibreNMS will
update to the stable release and continue to only update to stable releases.

!!! warning
    Downgrading is not supported on LibreNMS and will likely cause bugs.
