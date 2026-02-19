import requests

BOT_TOKEN = "8589110198:AAETPSIWj0VwclSL5aNKvvCooiQf4P5DOss"
CHAT_ID = 5629087724


def send_telegram(msg):
    url = f"https://api.telegram.org/bot{BOT_TOKEN}/sendMessage"
    payload = {"chat_id": CHAT_ID, "text": msg}

    try:
        r = requests.post(url, data=payload, timeout=5)
        r.raise_for_status()
        data = r.json()

        if data.get("ok"):
            message_id = data["result"]["message_id"]
            print(f"[OK] Sent successfully (message_id={message_id})")
            return True
        else:
            print(f"[ERROR] Telegram error: {data}")
            return False

    except requests.exceptions.RequestException as e:
        print(f"[ERROR] Network error: {e}")
        return False


if send_telegram("Feedback test message"):
    print("All good, continue program")
else:
    print("Retry / log error / raise alarm")
