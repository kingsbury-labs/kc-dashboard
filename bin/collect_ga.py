"""GA4 and Search Console numbers for each property, as JSON on stdout. Read-only: it writes no files.

Live:    python -I bin/collect_ga.py --credentials <service account json> --property <slug> <ga4 id> <site> [...]
Fixture: python -I bin/collect_ga.py --from-fixture <raw responses json>

The fixture holds the raw API responses the live path would get, so --from-fixture runs only the transform, with
no credentials and no network. The Google client libraries are imported only on the live path. One failing call
puts its error on that part of that property; every other property is still reported.
"""
import argparse
import datetime
import json
import math
import re
import sys

SCOPES = [
    "https://www.googleapis.com/auth/analytics.readonly",
    "https://www.googleapis.com/auth/webmasters.readonly",
]
WINDOW_DAYS = 28
TOP_PAGES = 10
SLUG = re.compile(r"^[a-z0-9][a-z0-9-]{0,63}$")
GA4_ID = re.compile(r"^[0-9]{1,20}$")
SITE = re.compile(r"^(https://[^\s]{1,200}|sc-domain:[a-z0-9.-]{1,200})$")


def error_of(part):
    """The error a raw response carries, as one short line, or None."""
    if isinstance(part, dict) and isinstance(part.get("error"), dict):
        err = part["error"]
        return ("%s %s" % (err.get("code", ""), err.get("message", "")))[:300].strip()
    if not isinstance(part, dict):
        return "no response"
    return None


def number(value):
    """A finite number, or None: a NaN or infinity from the API must not make the whole output unreadable."""
    try:
        n = float(value)
    except (TypeError, ValueError):
        return None
    if not math.isfinite(n):
        return None
    return int(n) if n.is_integer() else n


def metric_row(report, index=0):
    rows = report.get("rows") or []
    if not rows:
        return [0] * 2
    return [number(m.get("value")) for m in rows[index].get("metricValues", [])]


def transform_ga4(raw):
    for key in ("totals", "pages", "realtime"):
        err = error_of(raw.get(key))
        if err is not None:
            return None, err
    totals = metric_row(raw["totals"]) + [0, 0]
    pages = []
    for row in (raw["pages"].get("rows") or [])[:TOP_PAGES]:
        dims = row.get("dimensionValues") or [{}]
        mets = row.get("metricValues") or [{}]
        pages.append({"path": str(dims[0].get("value", ""))[:300], "views": number(mets[0].get("value"))})
    realtime = raw["realtime"].get("rows") or []
    active = number(realtime[0]["metricValues"][0].get("value")) if realtime else 0
    return {"sessions": totals[0], "users": totals[1], "active_users": active, "top_pages": pages}, None


def transform_search(raw):
    err = error_of(raw)
    if err is not None:
        return None, err
    rows = raw.get("rows") or [{}]
    row = rows[0]
    return {
        "clicks": number(row.get("clicks", 0)),
        "impressions": number(row.get("impressions", 0)),
        "ctr": number(row.get("ctr", 0)),
        "position": number(row.get("position", 0)),
    }, None


def transform(raw):
    out = []
    for prop in raw.get("properties", []):
        ga4, ga4_error = transform_ga4(prop.get("ga4") or {})
        search, search_error = transform_search(prop.get("search_console"))
        out.append({
            "slug": prop.get("slug"),
            "ga4_property": prop.get("ga4_property"),
            "search_console_site": prop.get("search_console_site"),
            "ga4": ga4,
            "ga4_error": ga4_error,
            "search_console": search,
            "search_console_error": search_error,
        })
    return {"window_days": WINDOW_DAYS, "properties": out}


def call(fn):
    """A raw response, or the error the API gave, in the same shape the fixture uses."""
    try:
        return fn()
    except Exception as e:  # one property's failure must not stop the others
        code = getattr(e, "code", None) or getattr(getattr(e, "resp", None), "status", "")
        code = getattr(code, "value", code)
        return {"error": {"code": code, "message": str(e).splitlines()[0][:300] if str(e) else type(e).__name__}}


def report_dict(resp):
    return {"rows": [{
        "dimensionValues": [{"value": d.value} for d in row.dimension_values],
        "metricValues": [{"value": m.value} for m in row.metric_values],
    } for row in resp.rows]}


def live(credentials, properties):
    from google.oauth2 import service_account
    from google.analytics.data_v1beta import BetaAnalyticsDataClient
    from google.analytics.data_v1beta.types import (
        DateRange, Dimension, Metric, OrderBy, RunRealtimeReportRequest, RunReportRequest)
    from googleapiclient.discovery import build

    creds = service_account.Credentials.from_service_account_file(credentials, scopes=SCOPES)
    ga = BetaAnalyticsDataClient(credentials=creds)
    sc = build("searchconsole", "v1", credentials=creds, cache_discovery=False)
    window = [DateRange(start_date="%ddaysAgo" % WINDOW_DAYS, end_date="yesterday")]
    today = datetime.date.today()
    body = {
        "startDate": (today - datetime.timedelta(days=WINDOW_DAYS)).isoformat(),
        "endDate": (today - datetime.timedelta(days=1)).isoformat(),
    }
    raw = {"properties": []}
    for slug, ga4_id, site in properties:
        name = "properties/" + ga4_id
        raw["properties"].append({
            "slug": slug,
            "ga4_property": ga4_id,
            "search_console_site": site,
            "ga4": {
                "totals": call(lambda: report_dict(ga.run_report(RunReportRequest(
                    property=name, date_ranges=window, metrics=[Metric(name="sessions"), Metric(name="totalUsers")])))),
                "pages": call(lambda: report_dict(ga.run_report(RunReportRequest(
                    property=name, date_ranges=window, dimensions=[Dimension(name="pagePath")],
                    metrics=[Metric(name="screenPageViews")], limit=TOP_PAGES,
                    order_bys=[OrderBy(metric=OrderBy.MetricOrderBy(metric_name="screenPageViews"), desc=True)])))),
                "realtime": call(lambda: report_dict(ga.run_realtime_report(RunRealtimeReportRequest(
                    property=name, metrics=[Metric(name="activeUsers")])))),
            },
            "search_console": call(lambda: sc.searchanalytics().query(siteUrl=site, body=body).execute()),
        })
    return raw


def main(argv):
    parser = argparse.ArgumentParser()
    parser.add_argument("--from-fixture")
    parser.add_argument("--credentials")
    parser.add_argument("--property", nargs=3, action="append", default=[], metavar=("SLUG", "GA4_ID", "SITE"))
    args = parser.parse_args(argv)
    if args.from_fixture:
        with open(args.from_fixture, "r", encoding="utf-8") as f:
            raw = json.load(f)
    else:
        if not args.credentials or not args.property:
            parser.error("--credentials and at least one --property are needed")
        for slug, ga4_id, site in args.property:
            if not SLUG.match(slug) or not GA4_ID.match(ga4_id) or not SITE.match(site):
                parser.error("bad --property " + slug)
        raw = live(args.credentials, args.property)
    json.dump(transform(raw), sys.stdout, allow_nan=False)
    sys.stdout.write("\n")
    return 0


if __name__ == "__main__":
    # Only the exception's type reaches stderr: its message or a traceback could carry a credential path's
    # contents, a token or a raw response, and GaCollector stores the last stderr line as the error.
    try:
        code = main(sys.argv[1:])
    except SystemExit:
        raise
    except BaseException as e:
        sys.stderr.write("collect_ga.py failed: %s\n" % type(e).__name__)
        sys.exit(1)
    sys.exit(code)
