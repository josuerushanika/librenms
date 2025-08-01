# OS Level Virtualization Monitoring

| OS      | Supported                           |
|---------|-------------------------------------|
| FreeBSD | jails                               |
| Linux   | cgroups v2(Docker, Podman included) |

### Install prereqs

=== "Debian/Ubuntu"

    ```bash
    apt-get install libjson-perl libclone-perl libmime-base64-perl libfile-slurp-perl libio-interface-perl cpanminus
    cpanm OSLV::Monitor
    ```

=== "FreeBSD"

    ```bash
    pkg install p5-OSLV-Monitor
    ```

=== "Generic"

    ```bash
    cpanm JSON Clone Mime::Base64 File::Slurp IO::Interface
    cpanm OSLV::Monitor
    ``` 

### SNMP Extend

1. Setup cron.

    ```bash
    */5 * * * * /usr/local/bin/oslv_monitor -q > /dev/null 2> /dev/null
    ```

2. Setup snmpd.

    ```bash
    extend oslv_monitor /bin/cat /var/cache/oslv_monitor/snmp
    ```

3. Restart snmpd.

    ```bash
    sudo systemctl restart snmpd
    ```

Wait for it to be rediscovered by LibreNMS.

An optional config file may be specified via -f or placed at
`/usr/local/etc/oslv_monitor.json`.

The following keys are used in the JSON config file.


| Option         | Description                                                      | Default    |
|----------------|------------------------------------------------------------------|------------|
| `include`      | An array of regular expressions to include.                      | `["^.*$"]` |
| `exclude`      | An array of regular expressions to exclude.                      | undef      |
| `backend`      | Override the backend and automatically choose it.                |            |
| `time_divider` | Override the time_divider value. see chapter under Time divider. |            |

#### Time divider 

The default value varies per backend and if it is needed. 

!!! note "cgroups"
    While the default for usec to sec conversion should be `1000000`, some settings report the value in nanoseconds, requiring `1000000000`.

| Backend  | Time Divider | Default |
|----------|--------------|---------|
| cgroups  | usec to sec   | 1000000 |
| FreeBSD  | Not used      |         |

#### Backend 

By Defaults the backends are as below.

| Backend  | Default    |
|----------|------------|
| FreeBSD  | FreeBSD    |
| Linux    | cgroups    |

#### Default would be like this.

```json
{
  "include": ["^.*$"]
}
```


### Metric Notes

| Key                     | Description                                                  |
|-------------------------|--------------------------------------------------------------|
| `running_$name`         | 0 or 1 based on if it is running or not.                     |
| `oslvm___$name___$stat` | The a specific stat for a specific OSLVMs.                   |
| `totals_$stat`          | A stat representing a total for all stats across all OSLVMs. |

Something is considered not running if it has been seen. How long
something is considred to have been seen is controlled by
`apps.oslv_monitor.seen_age`, which is the number of seconds ago it
would of have to be seen. The default is `604800` which is seven days
in seconds.

All time values are in seconds.

All counter stats are per second values for that time period.

### Backend Notes

#### FreeBSD

The stats names match those produced by `ps --libxo json`.

#### Linux cgroups v2

The cgroup to name mapping is done like below.

| Input         | Output    |
|---------------|-----------|
| systemd       | `s_$name` |
| user          | `u_$name` |
| docker        | `d_$name` |
| podman        | `p_$name` |
| anything else | `$name`   |


The following `ps` to stats mapping are as below.

| `ps`    | stats            |
|---------|------------------|
| `%cpu`  | `percent-cpu`    |
| `%mem`  | `percent-memory` |
| `rss`   | `rss`            |
| `vsize` | `virtual-size`   |
| `trs`   | `text-size`      |
| `drs`   | `data-size`      |
| `size`  | `size`           |


`procs` is a total number of procs in that cgroup.

The rest of the values are pulled from the following files with
the names kept as is.

- cpu.stat
- io.stat
- memory.stat

The following mappings are done though.

The following mappings are done though.

| cgroupv2          | stats            |
|-------------------|------------------|
| `pgfault`         | `minor-faults`   |
| `pgmajfault`      | `major-faults`   |
| `usage_usec`      | `cpu-time`       |
| `system_usec`     | `system-time`    |
| `user_usec`       | `user-time`      |
| `throttled_usecs` | `throttled-time` |

If the extended memory stat information pulled via C groups is not needed, it can be
disabled as below.

```
lnms config:set apps.oslv_monitor.linux_pg_memory_stats false
lnms config:set apps.oslv_monitor.misc_linux_memory_stats false
lnms config:set apps.oslv_monitor.zswap_size false
lnms config:set apps.oslv_monitor.zswap_activity false
lnms config:set apps.oslv_monitor.workingset_stats false
lnms config:set apps.oslv_monitor.thp_activity false
```

`apps.oslv_monitor.linux_pg_memory_stats` will disable graphs and stat gathering for the
items below.

- `pgactivate`
- `pgdeactivate`
- `pglazyfree`
- `pglazyfreed`
- `pgrefill`
- `pgscan`
- `pgscan_direct`
- `pgscan_khugepaged`
- `pgscan_kswapd`
- `pgsteal`
- `pgsteal_direct`
- `pgsteal_khugepaged`
- `pgsteal_kswapd`

`apps.oslv_monitor.misc_linux_memory_stats` will disable graphs and stat gathering for the
items below.

- `anon`
- `file`
- `kernel`
- `kernel_stack`
- `pagetables`
- `sec_pagetables`
- `percpu`
- `vmalloc`
- `shmem`
- `file_mapped`
- `file_dirty`
- `file_writeback`
- `swapcached`
- `anon_thp`
- `file_thp`
- `shmem_thp`
- `inactive_anon`
- `active_anon`
- `slab_reclaimable`
- `slab_unreclaimable`
- `slab`

`apps.oslv_monitor.zswap_size` will disable graphs and stat gathering for the
items below.

- `zswap`
- `zswapped`

`apps.oslv_monitor.zswap_activity` will disable graphs and stat gathering for the
items below.

- `zswpin`
- `zswpout`
- `zswpwb`

`apps.oslv_monitor.workingset_stats` will disable graphs and stat gathering for the
items below.

- `workingset_refault_anon`
- `workingset_refault_file`
- `workingset_activate_anon`
- `workingset_activate_file`
- `workingset_restore_anon`
- `workingset_restore_file`
- `workingset_nodereclaim`

`apps.oslv_monitor.thp_activity` will disable graphs and stat gathering for the
items below.

- `thp_fault_alloc`
- `thp_collapse_alloc`
- `thp_swpout`
- `thp_swpout_fallback`
