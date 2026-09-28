"""Upload ad videos to the DreamCar Meta ad account by public URL (Graph API /advideos, file_url).

Why: the Meta MCP media upload is not rolled out on this account (28.09.2026), so video ads need this path.
env: FB_ACCESS_TOKEN, AD_ACCOUNT_ID, VIDEOS = "name|url||name|url".
Prints one line per video: VIDEO <name> <video_id> <status>, and writes the same to the job summary.
"""
import json, os, sys, time, urllib.parse, urllib.request

API = "https://graph.facebook.com/v21.0"
TOKEN = os.environ["FB_ACCESS_TOKEN"]
ACT = os.environ["AD_ACCOUNT_ID"]


def call(method, path, params):
    params = dict(params, access_token=TOKEN)
    data = urllib.parse.urlencode(params).encode()
    if method == "GET":
        req = urllib.request.Request(f"{API}/{path}?{data.decode()}")
    else:
        req = urllib.request.Request(f"{API}/{path}", data=data, method="POST")
    try:
        with urllib.request.urlopen(req, timeout=300) as r:
            return json.load(r)
    except urllib.error.HTTPError as e:
        body = e.read().decode()
        print("HTTP", e.code, body[:800]); sys.exit(1)


def main():
    items = [x.split("|", 1) for x in os.environ["VIDEOS"].split("||") if x.strip()]
    out = []
    for name, url in items:
        r = call("POST", f"act_{ACT}/advideos", {"file_url": url.strip(), "name": name.strip()})
        vid = r["id"]
        status = "?"
        for _ in range(60):
            s = call("GET", vid, {"fields": "status"})
            status = s.get("status", {}).get("video_status", "?")
            if status in ("ready", "error"):
                break
            time.sleep(10)
        line = f"VIDEO {name.strip()} {vid} {status}"
        print(line); out.append(line)
        if status == "error":
            print(json.dumps(s)); sys.exit(1)
    summ = os.environ.get("GITHUB_STEP_SUMMARY")
    if summ:
        with open(summ, "a") as f:
            f.write("\n".join(out) + "\n")


if __name__ == "__main__":
    main()
