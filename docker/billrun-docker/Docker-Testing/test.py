from selenium import webdriver
from selenium.webdriver.common.by import By
from selenium.webdriver.chrome.options import Options

chrome_options = webdriver.ChromeOptions()
chrome_options.add_argument("--no-sandbox")
chrome_options.add_argument("--headless")
chrome_options.add_argument("--disable-gpu")
driver = webdriver.Chrome(options=chrome_options)

# driver.get("https://www.google.com/")
# print(driver.title)
driver.get("http://billrun/index.html#/")
driver.implicitly_wait(100)
username = driver.find_element(By.XPATH, "//input[@placeholder='Email address']")
username.send_keys("admin")
password = driver.find_element(By.XPATH, "//input[@placeholder='Password']")
password.send_keys("12345678")
driver.find_element(By.XPATH, "//button[@type='submit']").click()
driver.get("http://billrun/test/updaterowt?rebalance=1")
logs = driver.find_element(By.XPATH, "/html/body").text
print(logs)
# file1 = open("Logs.txt", "w")  # write mode
# file1.write(logs)
# print("Logs upload in file successfully")
# file1.close()
print("Code is successfully working")
