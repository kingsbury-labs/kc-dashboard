#!/usr/bin/env node
// Restarts a self-hosted GitHub Actions runner in WSL only when GitHub's own
// view says it is lost: the runner is offline, or a self-hosted job has
// waited over QUEUE_MIN minutes while every runner for that repo says it is
// idle. A run listed as "queued" while its runner is busy is normal (one
// runner takes jobs one at a time) and is left alone: on 2026-10-02 a
// restart on that reading killed a suite four minutes into its run.
//
// Run every 5 minutes by the Windows scheduled task "Claude runner watchdog".
// `--dry-run` decides and logs without restarting.

const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');

const QUEUE_MIN = 10;
const COOLDOWN_MIN = 15;
const LOG = path.join(os.homedir(), '.claude', 'logs', 'runner-watchdog.log');
const STATE = path.join(os.homedir(), '.claude', 'logs', 'runner-watchdog.json');

/**
 * The pure decision. runners: [{name, service, status, busy}] for one repo;
 * queuedSince: Date of the oldest queued self-hosted job, or null;
 * lastRestart: {serviceName: ms}. Returns [{service, reason}].
 */
function decide(runners, queuedSince, now, lastRestart = {}) {
    const cooled = (r) => now - (lastRestart[r.service] ?? 0) >= COOLDOWN_MIN * 60000;
    const out = [];

    for (const r of runners) {
        if (r.status !== 'online' && cooled(r)) {
            out.push({ service: r.service, reason: `GitHub reports ${r.name} ${r.status}` });
        }
    }

    const online = runners.filter((r) => r.status === 'online');
    const waited = queuedSince ? (now - queuedSince.getTime()) / 60000 : 0;

    if (waited > QUEUE_MIN && online.length > 0 && online.every((r) => !r.busy)) {
        for (const r of online.filter(cooled)) {
            out.push({ service: r.service, reason: `a job queued ${Math.round(waited)} min while ${r.name} says idle` });
        }
    }

    return out;
}

function log(line) {
    fs.mkdirSync(path.dirname(LOG), { recursive: true });
    fs.appendFileSync(LOG, `${new Date().toISOString()} ${line}\n`);
}

function sh(cmd, args) {
    return execFileSync(cmd, args, { encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'], windowsHide: true });
}

/** {repo: [{name, service}]} from the runner folders and the installed services in WSL. */
function localRunners() {
    const script = 'for d in ~/actions-runner*; do [ -f "$d/.runner" ] && python3 -c "import json,sys;d=json.load(open(sys.argv[1],encoding=\'utf-8-sig\'));print(d[\'gitHubUrl\'],d[\'agentName\'])" "$d/.runner"; done; echo ---; systemctl list-units --type=service --all --no-legend "actions.runner.*" | awk \'{print $1}\'';
    const [configs, units] = sh('wsl.exe', ['-e', 'bash', '-c', script]).split('---');
    const services = units.trim().split(/\s+/).filter(Boolean);
    const byRepo = {};

    for (const line of configs.trim().split('\n').filter(Boolean)) {
        const [url, name] = line.trim().split(' ');
        const service = services.find((s) => s.endsWith(`.${name}.service`));

        if (!service) continue; // a folder with no installed service is not ours to restart

        const repo = url.replace('https://github.com/', '');
        (byRepo[repo] ??= []).push({ name, service });
    }

    return byRepo;
}

function oldestQueuedSelfHosted(repo) {
    const runs = JSON.parse(sh('gh', ['api', `repos/${repo}/actions/runs?status=queued&per_page=20`])).workflow_runs;
    let oldest = null;

    for (const run of runs) {
        const jobs = JSON.parse(sh('gh', ['api', `repos/${repo}/actions/runs/${run.id}/jobs`])).jobs;

        for (const job of jobs) {
            if (job.status !== 'queued' || !job.labels.includes('self-hosted')) continue;
            const at = new Date(job.created_at);
            if (!oldest || at < oldest) oldest = at;
        }
    }

    return oldest;
}

function main() {
    const dry = process.argv.includes('--dry-run');
    let state = {};
    try { state = JSON.parse(fs.readFileSync(STATE, 'utf8')); } catch { /* first run */ }

    for (const [repo, local] of Object.entries(localRunners())) {
        try {
            const remote = JSON.parse(sh('gh', ['api', `repos/${repo}/actions/runners`])).runners;
            const runners = local.map((l) => {
                const r = remote.find((x) => x.name === l.name);
                return { ...l, status: r ? r.status : 'missing', busy: r ? r.busy : false };
            });

            for (const { service, reason } of decide(runners, oldestQueuedSelfHosted(repo), Date.now(), state)) {
                if (dry) {
                    log(`[dry-run] would restart ${service}: ${reason}`);
                    continue;
                }

                sh('wsl.exe', ['-e', 'sudo', '-n', 'systemctl', 'restart', service]);
                state[service] = Date.now();
                log(`restarted ${service}: ${reason}`);
            }
        } catch (e) {
            log(`${repo}: check failed, nothing restarted: ${String(e.message).split('\n')[0]}`);
        }
    }

    fs.mkdirSync(path.dirname(STATE), { recursive: true });
    fs.writeFileSync(STATE, JSON.stringify(state));
}

if (require.main === module) main();

module.exports = { decide, QUEUE_MIN, COOLDOWN_MIN };
